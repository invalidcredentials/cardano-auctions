<?php
/**
 * CardanoTransactionSignerPHP — CBOR codec + Ed25519 transaction signing.
 * Embedded from php-cardano for standalone operation.
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( class_exists( 'CardanoTransactionSignerPHP' ) ) return;

class CardanoTransactionSignerPHP
{
    /**
     * Sign a CBOR-hex transaction with a private key.
     *
     * @param string $tx_hex  Unsigned transaction CBOR hex.
     * @param string $skey_hex  64 hex chars (32-byte seed) or 128 hex chars (64-byte kL||kR extended).
     * @param bool   $debug   Enable debug logging.
     * @return array { success, signedTx, witnessSetHex, vkey_hex, sig_hex, debug }
     */
    public static function signTransaction(string $tx_hex, string $skey_hex, bool $debug = false): array
    {
        $log = [];
        try {
            if ($tx_hex === '') return ['success'=>false,'error'=>'Missing tx hex','debug'=>$log];
            $tx_bytes = hex2bin($tx_hex);
            if ($tx_bytes === false) return ['success'=>false,'error'=>'Invalid hex','debug'=>$log];

            $body_bytes = self::extractBodyBytes($tx_bytes);
            $body_hash  = sodium_crypto_generichash($body_bytes, '', 32);

            $tx = self::decodeCBOR($tx_bytes);
            if (!is_array($tx) || count($tx) < 2) return ['success'=>false,'error'=>'Invalid tx structure','debug'=>$log];
            $body = $tx[0];
            $wset = isset($tx[1]) && is_array($tx[1]) ? $tx[1] : [];

            $sig = null; $pub = null;
            if (strlen($skey_hex) === 128) {
                $kL = hex2bin(substr($skey_hex, 0, 64));
                $kR = hex2bin(substr($skey_hex, 64, 64));
                if ($kL === false || $kR === false) return ['success'=>false,'error'=>'Invalid extended key','debug'=>$log];
                $pub = Ed25519Compat::ge_scalarmult_base_noclamp($kL);
                $sig = Ed25519Compat::sign_extended($body_hash, $kL, $kR);
            } elseif (strlen($skey_hex) === 64) {
                $seed = hex2bin($skey_hex);
                if ($seed === false) return ['success'=>false,'error'=>'Invalid key hex','debug'=>$log];
                $kp = sodium_crypto_sign_seed_keypair($seed);
                $sk = sodium_crypto_sign_secretkey($kp);
                $pub = sodium_crypto_sign_publickey($kp);
                $sig = sodium_crypto_sign_detached($body_hash, $sk);
            } else {
                return ['success'=>false,'error'=>'Key must be 64 or 128 hex chars','debug'=>$log];
            }

            $vkey_witness = [$pub, $sig];
            $vkey_witnesses_set = ['__cbor_tag__' => 258, '__value__' => [$vkey_witness]];
            $our_witness_set_map = [0 => $vkey_witnesses_set];

            if (!isset($wset[0])) $wset[0] = [];
            $wset[0][] = $vkey_witness;

            // Preserve the full transaction structure (body, witnesses, is_valid, auxiliary).
            $tx[1] = $wset;
            $tx_signed_bytes = self::encodeCBOR($tx);
            $witness_set_bytes = self::encodeMap($our_witness_set_map);

            return [
                'success' => true,
                'signedTx' => bin2hex($tx_signed_bytes),
                'witnessSetHex' => bin2hex($witness_set_bytes),
                'vkey_hex' => bin2hex($pub),
                'sig_hex'  => bin2hex($sig),
                'debug' => $log
            ];
        } catch (\Throwable $e) {
            return ['success'=>false,'error'=>$e->getMessage(),'debug'=>$log];
        }
    }

    private static function extractBodyBytes(string $tx_bytes): string {
        $offset = 0;
        $first_byte = ord($tx_bytes[$offset]);
        if (($first_byte & 0xE0) != 0x80) throw new \RuntimeException('Transaction must be a CBOR array');
        $offset++;
        $body_start = $offset;
        self::skipCborElement($tx_bytes, $offset);
        return substr($tx_bytes, $body_start, $offset - $body_start);
    }

    private static function skipCborElement(string $bytes, int &$offset): void {
        $initial = ord($bytes[$offset]); $major = ($initial >> 5) & 0x07; $add = $initial & 0x1F; $offset++;
        $length = 0;
        if ($add < 24) $length = $add;
        elseif ($add == 24) { $length = ord($bytes[$offset]); $offset++; }
        elseif ($add == 25) { $length = unpack('n', substr($bytes, $offset, 2))[1]; $offset += 2; }
        elseif ($add == 26) { $length = unpack('N', substr($bytes, $offset, 4))[1]; $offset += 4; }
        elseif ($add == 27) { $offset += 8; $length = 0; }

        if ($major == 0 || $major == 1 || $major == 7) return;
        if ($major == 2 || $major == 3) { $offset += $length; return; }
        if ($major == 4) { for ($i = 0; $i < $length; $i++) self::skipCborElement($bytes, $offset); return; }
        if ($major == 5) { for ($i = 0; $i < $length; $i++) { self::skipCborElement($bytes, $offset); self::skipCborElement($bytes, $offset); } return; }
        if ($major == 6) { self::skipCborElement($bytes, $offset); return; }
    }

    /* ── CBOR Encode ───────────────────────────────────────────────── */

    public static function encodeCBOR($value): string {
        switch (gettype($value)) {
            case 'integer': return $value >= 0 ? self::encodeUnsigned($value) : self::encodeNegative($value);
            case 'string':  return self::encodeBytes($value);
            case 'array':
                if (isset($value['__cbor_tag__']) && isset($value['__value__'])) return self::encodeTagged($value['__cbor_tag__'], $value['__value__']);
                if (self::isAssoc($value)) return self::encodeMap($value);
                return self::encodeArray($value);
            case 'double':   return "\xFB" . pack('E', $value);
            case 'NULL':     return "\xF6";
            case 'boolean':  return $value ? "\xF5" : "\xF4";
            default: throw new \RuntimeException('Unsupported CBOR type: ' . gettype($value));
        }
    }

    private static function isAssoc(array $arr): bool { $i=0; foreach($arr as $k=>$_) if($k!==$i++) return true; return false; }

    private static function encodeUnsigned($v): string {
        if ($v < 24) return chr($v);
        if ($v < 256) return "\x18" . chr($v);
        if ($v < 65536) return "\x19" . pack('n', $v);
        if ($v < 4294967296) return "\x1A" . pack('N', $v);
        $hi = (int)bcdiv((string)$v,'4294967296',0); $lo = (int)bcmod((string)$v,'4294967296');
        return "\x1B" . pack('N',$hi) . pack('N',$lo);
    }

    private static function encodeNegative($v): string {
        $n = -1 - $v;
        if ($n < 24) return chr(0x20 | $n);
        if ($n < 256) return "\x38" . chr($n);
        if ($n < 65536) return "\x39" . pack('n', $n);
        if ($n < 4294967296) return "\x3A" . pack('N', $n);
        $hi = (int)bcdiv((string)$n,'4294967296',0); $lo = (int)bcmod((string)$n,'4294967296');
        return "\x3B" . pack('N',$hi) . pack('N',$lo);
    }

    private static function encodeBytes(string $s): string {
        $len = strlen($s);
        if ($len < 24) return chr(0x40 | $len) . $s;
        if ($len < 256) return "\x58" . chr($len) . $s;
        if ($len < 65536) return "\x59" . pack('n', $len) . $s;
        return "\x5A" . pack('N', $len) . $s;
    }

    private static function encodeArray(array $arr): string {
        $n = count($arr); $out = '';
        if ($n < 24) $out .= chr(0x80 | $n);
        elseif ($n < 256) $out .= "\x98" . chr($n);
        else $out .= "\x99" . pack('n', $n);
        foreach ($arr as $v) $out .= self::encodeCBOR($v);
        return $out;
    }

    public static function encodeMap(array $map): string {
        $n = count($map); $out = '';
        if ($n < 24) $out .= chr(0xA0 | $n);
        elseif ($n < 256) $out .= "\xB8" . chr($n);
        else $out .= "\xB9" . pack('n', $n);
        $allInt = true;
        foreach ($map as $k => $_) if (!is_int($k)) { $allInt = false; break; }
        if ($allInt) ksort($map, SORT_NUMERIC);
        foreach ($map as $k=>$v) $out .= self::encodeCBOR($k) . self::encodeCBOR($v);
        return $out;
    }

    private static function encodeTagged($tag, $value): string {
        $out = '';
        if ($tag < 24) $out .= chr(0xC0 | $tag);
        elseif ($tag < 256) $out .= "\xD8" . chr($tag);
        elseif ($tag < 65536) $out .= "\xD9" . pack('n', $tag);
        else $out .= "\xDA" . pack('N', $tag);
        $out .= self::encodeCBOR($value);
        return $out;
    }

    /* ── CBOR Decode ───────────────────────────────────────────────── */

    public static function decodeCBOR(string $bytes) { $ofs = 0; return self::decodeOne($bytes, $ofs); }

    private static function decodeOne(string $b, int &$ofs) {
        $ai = ord($b[$ofs]); $ofs++; $maj = $ai >> 5; $add = $ai & 31;
        $getn = function(int $n) use ($b, &$ofs) { $s = substr($b, $ofs, $n); $ofs += $n; return $s; };
        $getu = function(int $n) use ($getn) {
            $d = $getn($n);
            if ($n===1) return ord($d); if ($n===2) return unpack('n',$d)[1];
            if ($n===4) return unpack('N',$d)[1];
            if ($n===8) { $hi=unpack('N',substr($d,0,4))[1]; $lo=unpack('N',substr($d,4,4))[1]; return bcadd(bcmul($hi,'4294967296'),$lo); }
            return 0;
        };
        $readLen = function($add) use ($getu) {
            if ($add < 24) return $add; if ($add===24) return $getu(1); if ($add===25) return $getu(2);
            if ($add===26) return $getu(4); if ($add===27) return $getu(8);
            throw new \RuntimeException('Indefinite lengths not supported');
        };
        if ($maj===0) return $readLen($add);
        if ($maj===1) return -1 - $readLen($add);
        if ($maj===2) { $len=$readLen($add); $s=substr($b,$ofs,$len); $ofs+=$len; return $s; }
        if ($maj===3) { $len=$readLen($add); $s=substr($b,$ofs,$len); $ofs+=$len; return $s; }
        if ($maj===4) { $len=$readLen($add); $arr=[]; for($i=0;$i<$len;$i++) $arr[]=self::decodeOne($b,$ofs); return $arr; }
        if ($maj===5) { $len=$readLen($add); $map=[]; for($i=0;$i<$len;$i++){ $k=self::decodeOne($b,$ofs); $map[$k]=self::decodeOne($b,$ofs); } return $map; }
        if ($maj===6) { $readLen($add); return self::decodeOne($b,$ofs); }
        if ($maj===7) { if ($add===20) return false; if ($add===21) return true; if ($add===22) return null; }
        throw new \RuntimeException('Unsupported CBOR');
    }
}
