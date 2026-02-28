<?php
/**
 * Ed25519Compat — compat layer for Ed25519 scalarmult/signing.
 * Native ext/sodium → FFI → pure-PHP (BCMath) fallback.
 * Embedded from php-cardano for standalone operation.
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( class_exists( 'Ed25519Compat' ) ) return;

final class Ed25519Compat
{
    private static $ready = false;
    private static $hasNative = false;
    private static $hasFFI = false;
    private static $ffi = null;

    private const L_LE  = "\xED\xD3\xF5\x5C\x1A\x63\x12\x58\xD6\x9C\xF7\xA2\xDE\xF9\xDE\x14\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x10";
    private const L_DEC = '7237005577332262213973186563042994240857116359379907606001950938285454250989';

    public static function init(): void
    {
        if (self::$ready) return;
        self::$hasNative =
            function_exists('sodium_crypto_scalarmult_ed25519_base') &&
            function_exists('sodium_crypto_core_ed25519_scalar_add') &&
            function_exists('sodium_crypto_core_ed25519_scalar_mul') &&
            function_exists('sodium_crypto_core_ed25519_scalar_reduce');
        if (self::$hasNative) { self::$ready = true; return; }
        if (extension_loaded('FFI')) {
            $cdef = "
                int  crypto_core_ed25519_scalar_add(unsigned char *z, const unsigned char *x, const unsigned char *y);
                int  crypto_core_ed25519_scalar_mul(unsigned char *z, const unsigned char *x, const unsigned char *y);
                void crypto_core_ed25519_scalar_reduce(unsigned char *r, const unsigned char *s);
                int  crypto_scalarmult_ed25519_base(unsigned char *q, const unsigned char *n);
                int  crypto_scalarmult_ed25519_base_noclamp(unsigned char *q, const unsigned char *n);
            ";
            $candidates = ['libsodium','sodium','libsodium.so','libsodium.so.23','libsodium.so.26','libsodium.dylib','libsodium.dll'];
            foreach ($candidates as $lib) {
                try {
                    $ffi = \FFI::cdef($cdef, $lib);
                    $bufZ = \FFI::new('unsigned char[32]'); $bufX = \FFI::new('unsigned char[32]'); $bufY = \FFI::new('unsigned char[32]');
                    $ok = @$ffi->crypto_core_ed25519_scalar_add($bufZ, $bufX, $bufY);
                    if ($ok === 0) { self::$ffi = $ffi; self::$hasFFI = true; break; }
                } catch (\Throwable $e) { /* try next */ }
            }
        }
        self::$ready = true;
    }

    public static function hasNative(): bool { self::init(); return self::$hasNative; }
    public static function hasFFI(): bool    { self::init(); return self::$hasFFI;  }

    public static function add_raw32(string $a, string $b): string {
        if (strlen($a)!==32 || strlen($b)!==32) throw new \InvalidArgumentException('add_raw32 needs 32+32 bytes');
        $r = str_repeat("\x00", 32); $carry = 0;
        for ($i = 0; $i < 32; $i++) { $sum = ord($a[$i]) + ord($b[$i]) + $carry; $r[$i] = chr($sum & 0xFF); $carry = $sum >> 8; }
        return $r;
    }

    public static function add_modL(string $x, string $y): string {
        self::init();
        if (strlen($x)!==32 || strlen($y)!==32) throw new \InvalidArgumentException('add_modL needs 32+32 bytes');
        if (self::$hasNative) return sodium_crypto_core_ed25519_scalar_add($x,$y);
        if (self::$hasFFI) {
            $ffi=self::$ffi; $Z=\FFI::new('unsigned char[32]'); $X=\FFI::new('unsigned char[32]'); $Y=\FFI::new('unsigned char[32]');
            \FFI::memcpy($X,$x,32); \FFI::memcpy($Y,$y,32); $ffi->crypto_core_ed25519_scalar_add($Z,$X,$Y); return \FFI::string($Z,32);
        }
        $r = self::le_add($x, $y);
        if (!self::le_less($r, self::L_LE)) $r = self::le_sub($r, self::L_LE);
        return $r;
    }

    public static function scalar_add(string $x, string $y): string { return self::add_modL($x, $y); }

    public static function scalar_mul(string $x, string $y): string {
        self::init();
        if (strlen($x)!==32 || strlen($y)!==32) throw new \InvalidArgumentException('scalar_mul needs 32+32 bytes');
        if (self::$hasNative) return sodium_crypto_core_ed25519_scalar_mul($x,$y);
        if (self::$hasFFI) {
            $ffi=self::$ffi; $Z=\FFI::new('unsigned char[32]'); $X=\FFI::new('unsigned char[32]'); $Y=\FFI::new('unsigned char[32]');
            \FFI::memcpy($X,$x,32); \FFI::memcpy($Y,$y,32); $ffi->crypto_core_ed25519_scalar_mul($Z,$X,$Y); return \FFI::string($Z,32);
        }
        $dec = self::le_to_dec($x); $dey = self::le_to_dec($y);
        return self::dec_to_le(bcmod(bcmul($dec, $dey, 0), self::L_DEC), 32);
    }

    public static function scalar_reduce64(string $s): string {
        self::init();
        if (strlen($s)!==64) throw new \InvalidArgumentException('scalar_reduce64 needs 64 bytes');
        if (self::$hasNative) return sodium_crypto_core_ed25519_scalar_reduce($s);
        if (self::$hasFFI) { $ffi=self::$ffi; $R=\FFI::new('unsigned char[32]'); $S=\FFI::new('unsigned char[64]'); \FFI::memcpy($S,$s,64); $ffi->crypto_core_ed25519_scalar_reduce($R,$S); return \FFI::string($R,32); }
        return self::dec_to_le(bcmod(self::le_to_dec($s), self::L_DEC), 32);
    }

    public static function scalar_mul_small8(string $x): string {
        $carry=0; $r=str_repeat("\x00",32);
        for($i=0;$i<32;$i++){ $v=(ord($x[$i])<<3)|$carry; $r[$i]=chr($v & 0xFF); $carry=($v>>8)&0xFF; }
        if (!self::le_less($r, self::L_LE)) $r=self::le_sub($r, self::L_LE);
        return $r;
    }

    public static function ge_scalarmult_base_noclamp(string $k): string {
        self::init();
        if (strlen($k)!==32) throw new \InvalidArgumentException('need 32-byte scalar');
        if (self::$hasNative) {
            if (function_exists('sodium_crypto_scalarmult_ed25519_base_noclamp')) return sodium_crypto_scalarmult_ed25519_base_noclamp($k);
            return sodium_crypto_scalarmult_ed25519_base($k);
        }
        if (self::$hasFFI) {
            $ffi=self::$ffi; $Q=\FFI::new('unsigned char[32]'); $N=\FFI::new('unsigned char[32]'); \FFI::memcpy($N,$k,32);
            try { $ok=$ffi->crypto_scalarmult_ed25519_base_noclamp($Q,$N); }
            catch(\Throwable $e){ $ok=$ffi->crypto_scalarmult_ed25519_base($Q,$N); }
            if ($ok!==0) throw new \RuntimeException('FFI scalarmult_base failed');
            return \FFI::string($Q,32);
        }
        return Ed25519Pure::ge_scalarmult_base_noclamp($k);
    }

    public static function sign_extended(string $msg, string $kL, string $kR): string {
        $A = self::ge_scalarmult_base_noclamp($kL);
        $r = self::scalar_reduce64(hash('sha512', $kR . $msg, true));
        $R = self::ge_scalarmult_base_noclamp($r);
        $h = self::scalar_reduce64(hash('sha512', $R . $A . $msg, true));
        $hkL = self::scalar_mul($h, $kL);
        $S   = self::add_modL($r, $hkL);
        $sig = $R . $S;
        if (function_exists('sodium_memzero')) { sodium_memzero($r); sodium_memzero($h); sodium_memzero($hkL); }
        return $sig;
    }

    private static function le_add(string $a, string $b): string {
        $r=str_repeat("\x00",32); $carry=0;
        for($i=0;$i<32;$i++){ $sum=ord($a[$i])+ord($b[$i])+$carry; $r[$i]=chr($sum & 0xFF); $carry=$sum>>8; }
        return $r;
    }
    private static function le_sub(string $a, string $b): string {
        $r=str_repeat("\x00",32); $borrow=0;
        for($i=0;$i<32;$i++){ $ai=ord($a[$i]); $bi=ord($b[$i])+$borrow; if($ai<$bi){ $ai+=256; $borrow=1;} else {$borrow=0;} $r[$i]=chr($ai-$bi); }
        return $r;
    }
    private static function le_less(string $a, string $b): bool {
        for($i=31;$i>=0;$i--){ $ai=ord($a[$i]); $bi=ord($b[$i]); if($ai!==$bi) return $ai<$bi; } return false;
    }
    private static function le_to_dec(string $le): string {
        if(!function_exists('bcadd')) throw new \RuntimeException('BCMath required');
        $n='0'; $base='1'; $len=strlen($le);
        for($i=0;$i<$len;$i++){ $n=bcadd($n, bcmul((string)ord($le[$i]), $base, 0), 0); $base=bcmul($base, '256', 0); }
        return $n;
    }
    private static function dec_to_le(string $dec, int $len): string {
        if(!function_exists('bcdiv')) throw new \RuntimeException('BCMath required');
        $bytes=[]; $n=ltrim($dec,'+');
        for($i=0;$i<$len;$i++){ $bytes[]=chr((int)bcmod($n,'256')); $n=bcdiv($n,'256',0); }
        return implode('', $bytes);
    }
}
