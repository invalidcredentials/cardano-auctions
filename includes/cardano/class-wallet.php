<?php
/**
 * CardanoWalletPHP — CIP-1852 HD wallet derivation (Icarus root).
 * Embedded from php-cardano for standalone operation.
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( class_exists( 'CardanoWalletPHP' ) ) return;

class CardanoWalletPHP
{
    public static function fromMnemonic(string $mnemonic, string $passphrase = '', string $network = 'preprod', int $address_index = 0): array
    {
        self::checkRequirements();
        $root = self::deriveRootKeyIcarusFromMnemonic($mnemonic, $passphrase);
        $purpose = self::deriveChild($root, 1852, true);
        $coin    = self::deriveChild($purpose, 1815, true);
        $acct0   = self::deriveChild($coin, 0, true);
        $pay_chain  = self::deriveChild($acct0, 0, false);
        $pay_key    = self::deriveChild($pay_chain, $address_index, false);
        $stake_chain= self::deriveChild($acct0, 2, false);
        $stake_key  = self::deriveChild($stake_chain, 0, false);
        $addresses = self::buildAddresses($pay_key['public_key'], $stake_key['public_key'], $network);
        return [
            'success' => true,
            'root'    => $root,
            'account' => $acct0,
            'payment' => $pay_key,
            'stake'   => $stake_key,
            'addresses' => $addresses,
            'payment_skey_hex' => bin2hex($pay_key['kL']),
            'payment_skey_extended' => bin2hex($pay_key['kL'] . $pay_key['kR']),
            'payment_pkey_hex' => bin2hex($pay_key['public_key']),
            'payment_keyhash'  => self::blake2b224_hex($pay_key['public_key']),
            'stake_skey_hex'   => bin2hex($stake_key['kL']),
            'stake_skey_extended' => bin2hex($stake_key['kL'] . $stake_key['kR']),
            'stake_keyhash'    => self::blake2b224_hex($stake_key['public_key']),
        ];
    }

    public static function generateWallet(string $network = 'preprod'): array
    {
        self::checkRequirements();
        $mnemonic = self::generateMnemonic(24);
        $wallet = self::fromMnemonic($mnemonic, '', $network);
        $wallet['mnemonic'] = $mnemonic;
        return $wallet;
    }

    private static function deriveRootKeyIcarusFromMnemonic(string $mnemonic, string $passphrase = ''): array
    {
        $entropy = self::mnemonicToEntropyBytes($mnemonic);
        if (class_exists('\Normalizer')) $passphrase = \Normalizer::normalize($passphrase, \Normalizer::FORM_KD);
        $dk = hash_pbkdf2('sha512', $passphrase, $entropy, 4096, 96, true);
        $kL = substr($dk, 0, 32); $kR = substr($dk, 32, 32); $cc = substr($dk, 64, 32);
        if (function_exists('sodium_memzero')) sodium_memzero($dk);
        $kL[0]  = $kL[0]  & "\xF8";
        $kL[31] = $kL[31] & "\x1F";
        $kL[31] = $kL[31] | "\x40";
        $A = Ed25519Compat::ge_scalarmult_base_noclamp($kL);
        return ['kL'=>$kL, 'kR'=>$kR, 'chain_code'=>$cc, 'public_key'=>$A];
    }

    private static function deriveChild(array $parent, int $index, bool $hardened): array
    {
        $cP = $parent['chain_code']; $kL = $parent['kL']; $kR = $parent['kR'];
        $i = $hardened ? ($index | 0x80000000) : $index;
        $i_le = pack('V', $i);
        if ($hardened) {
            $blob = $kL . $kR;
            $dataZ = "\x00" . $blob . $i_le;
            $dataC = "\x01" . $blob . $i_le;
        } else {
            $A = Ed25519Compat::ge_scalarmult_base_noclamp($kL);
            $dataZ = "\x02" . $A . $i_le;
            $dataC = "\x03" . $A . $i_le;
        }
        $Z  = hash_hmac('sha512', $dataZ, $cP, true);
        $ZL = substr($Z, 0, 28) . "\x00\x00\x00\x00";
        $ZR = substr($Z, 32, 32);
        $t = Ed25519Compat::scalar_mul_small8($ZL);
        $kL_child = Ed25519Compat::add_raw32($kL, $t);
        $kR_child = $kR; $carry = 0;
        for ($j = 0; $j < 32; $j++) {
            $sum = ord($kR_child[$j]) + ord($ZR[$j]) + $carry;
            $kR_child[$j] = chr($sum & 0xFF); $carry = $sum >> 8;
        }
        $c_full  = hash_hmac('sha512', $dataC, $cP, true);
        $c_child = substr($c_full, 32, 32);
        $A_child = Ed25519Compat::ge_scalarmult_base_noclamp($kL_child);
        return ['kL'=>$kL_child, 'kR'=>$kR_child, 'chain_code'=>$c_child, 'public_key'=>$A_child];
    }

    private static function buildAddresses(string $pay_vkey, string $stake_vkey, string $network): array
    {
        $mainnet = ($network === 'mainnet');
        $network_id = $mainnet ? 1 : 0;
        $pay_hash   = hex2bin(self::blake2b224_hex($pay_vkey));
        $stake_hash = hex2bin(self::blake2b224_hex($stake_vkey));
        $header = chr(($network_id & 0x0F));
        $addr_bytes = $header . $pay_hash . $stake_hash;
        $addr_hrp = $mainnet ? 'addr' : 'addr_test';
        $addr_bech32 = self::bech32Encode($addr_hrp, self::to5Bits($addr_bytes));
        $sheader = chr(0xE0 | ($network_id & 0x0F));
        $stake_bytes = $sheader . $stake_hash;
        $stake_hrp = $mainnet ? 'stake' : 'stake_test';
        $stake_bech32 = self::bech32Encode($stake_hrp, self::to5Bits($stake_bytes));
        return ['payment_address' => $addr_bech32, 'stake_address' => $stake_bech32];
    }

    /* ── BIP39 ─────────────────────────────────────────────────────── */

    private static $WORDLIST = null;

    private static function getWordlist(): array {
        if (self::$WORDLIST !== null) return self::$WORDLIST;
        self::$WORDLIST = require __DIR__ . '/bip39-wordlist.php';
        return self::$WORDLIST;
    }

    private static function generateMnemonic(int $wordCount = 24): string {
        $valid = [12=>128, 15=>160, 18=>192, 21=>224, 24=>256];
        if (!isset($valid[$wordCount])) $wordCount = 24;
        $ENT = $valid[$wordCount]; $CS = $ENT / 32;
        $entropy = random_bytes($ENT / 8);
        $hash = hash('sha256', $entropy, true);
        $bits = self::binToBits($entropy) . substr(self::binToBits($hash), 0, $CS);
        return implode(' ', self::bitsToWords($bits));
    }

    private static function mnemonicToEntropyBytes(string $mnemonic): string {
        $words = preg_split('/\s+/', trim($mnemonic));
        $n = count($words);
        $valid = [12=>128, 15=>160, 18=>192, 21=>224, 24=>256];
        if (!isset($valid[$n])) throw new \InvalidArgumentException('Unsupported mnemonic length');
        $ENT = $valid[$n]; $CS = $ENT / 32;
        $wl = self::getWordlist(); $map = array_flip($wl);
        $bits = '';
        foreach ($words as $w) {
            if (!isset($map[$w])) throw new \InvalidArgumentException("Word not in BIP39 list: {$w}");
            $bits .= str_pad(decbin($map[$w]), 11, '0', STR_PAD_LEFT);
        }
        return self::bitsToBin(substr($bits, 0, $ENT));
    }

    private static function binToBits(string $bin): string {
        $out = ''; for ($i=0; $i<strlen($bin); $i++) $out .= str_pad(decbin(ord($bin[$i])), 8, '0', STR_PAD_LEFT);
        return $out;
    }

    private static function bitsToBin(string $bits): string {
        $out = '';
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $byte = substr($bits, $i, 8);
            if (strlen($byte) < 8) $byte = str_pad($byte, 8, '0', STR_PAD_RIGHT);
            $out .= chr(bindec($byte));
        }
        return $out;
    }

    private static function bitsToWords(string $bits): array {
        $wl = self::getWordlist(); $out = [];
        for ($i=0; $i<strlen($bits); $i+=11) $out[] = $wl[bindec(substr($bits, $i, 11))];
        return $out;
    }

    /* ── Hashing ───────────────────────────────────────────────────── */

    private static function blake2b224_hex(string $data): string {
        return bin2hex(sodium_crypto_generichash($data, '', 28));
    }

    /* ── Bech32 ────────────────────────────────────────────────────── */

    private static function bech32Encode(string $hrp, array $data5): string {
        $charset = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
        $chk = self::bech32Polymod(array_merge(self::bech32HrpExpand($hrp), $data5, [0,0,0,0,0,0])) ^ 1;
        $checksum = [];
        for ($p = 0; $p < 6; $p++) $checksum[] = ($chk >> (5 * (5 - $p))) & 31;
        $combined = array_merge($data5, $checksum);
        $out = $hrp . '1';
        foreach ($combined as $v) $out .= $charset[$v];
        return $out;
    }

    private static function to5Bits(string $bytes): array {
        $bits = 0; $value = 0; $out = [];
        for ($i=0; $i<strlen($bytes); $i++) { $value = ($value << 8) | ord($bytes[$i]); $bits += 8;
            while ($bits >= 5) { $out[] = ($value >> ($bits - 5)) & 31; $bits -= 5; } }
        if ($bits > 0) $out[] = ($value << (5 - $bits)) & 31;
        return $out;
    }

    private static function bech32HrpExpand(string $hrp): array {
        $out = [];
        for ($i=0; $i<strlen($hrp); $i++) $out[] = ord($hrp[$i]) >> 5;
        $out[] = 0;
        for ($i=0; $i<strlen($hrp); $i++) $out[] = ord($hrp[$i]) & 31;
        return $out;
    }

    private static function bech32Polymod(array $values): int {
        $GEN = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3]; $chk = 1;
        foreach ($values as $v) { $b = $chk >> 25; $chk = (($chk & 0x1ffffff) << 5) ^ $v;
            for ($i=0; $i<5; $i++) if ((($b >> $i) & 1) !== 0) $chk ^= $GEN[$i]; }
        return $chk;
    }

    private static function checkRequirements(): void {
        if (!extension_loaded('sodium')) throw new \RuntimeException('ext/sodium is required');
        if (!function_exists('hash_pbkdf2')) throw new \RuntimeException('hash_pbkdf2 is required');
        if (!extension_loaded('bcmath')) throw new \RuntimeException('ext/bcmath is required');
        Ed25519Compat::init();
    }
}
