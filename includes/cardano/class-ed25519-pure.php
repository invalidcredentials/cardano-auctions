<?php
/**
 * Ed25519Pure — pure-PHP (BCMath) Ed25519 basepoint multiplication.
 * Embedded from php-cardano for standalone operation.
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( class_exists( 'Ed25519Pure' ) ) return;

final class Ed25519Pure
{
    private const P_DEC = '57896044618658097711785492504343953926634992332820282019728792003956564819949';
    private const D_DEC = '37095705934669439343138083508754565189542113879843219016388785533085940283555';
    private const TWO_D_DEC = '74191411869338878686276167017509130379084227759686438032777571066171880567110';
    private const Bx_DEC = '15112221349535400772501151409588531511454012693041857206046113283949847762202';
    private const By_DEC = '46316835694926478169428394003475163141307993866256225615783033603165251855960';
    private const ZERO = '0';
    private const ONE  = '1';

    public static function ge_scalarmult_base_noclamp(string $k_le): string
    {
        if (strlen($k_le) !== 32) throw new \InvalidArgumentException('k must be 32 bytes');
        $B = self::point_from_affine(self::Bx_DEC, self::By_DEC);
        $R = ['X'=>self::ZERO, 'Y'=>self::ONE, 'Z'=>self::ONE, 'T'=>self::ZERO];
        for ($bit = 255; $bit >= 0; $bit--) {
            $R = self::point_double($R);
            $b = (ord($k_le[$bit >> 3]) >> ($bit & 7)) & 1;
            if ($b) $R = self::point_add($R, $B);
        }
        [$x, $y] = self::to_affine($R);
        $y_le = self::dec_to_le($y, 32);
        $x_parity = (int) bcmod($x, '2');
        $last = ord($y_le[31]);
        $last = $x_parity ? ($last | 0x80) : ($last & 0x7F);
        $y_le[31] = chr($last);
        return $y_le;
    }

    public static function A_from_kL(string $kL): string { return self::ge_scalarmult_base_noclamp($kL); }

    private static function point_from_affine(string $x, string $y): array {
        $x = self::modp($x); $y = self::modp($y);
        return ['X'=>$x, 'Y'=>$y, 'Z'=>self::ONE, 'T'=>self::mul_modp($x, $y)];
    }

    private static function point_add(array $P, array $Q): array {
        $A = self::mul_modp(self::sub_modp($P['Y'], $P['X']), self::sub_modp($Q['Y'], $Q['X']));
        $B = self::mul_modp(self::add_modp($P['Y'], $P['X']), self::add_modp($Q['Y'], $Q['X']));
        $C = self::mul_modp(self::mul_modp(self::TWO_D_DEC, $P['T']), $Q['T']);
        $D = self::mul_modp(self::add_modp($P['Z'], $P['Z']), $Q['Z']);
        $E = self::sub_modp($B, $A); $F = self::sub_modp($D, $C);
        $G = self::add_modp($D, $C); $H = self::add_modp($B, $A);
        return ['X'=>self::mul_modp($E,$F), 'Y'=>self::mul_modp($G,$H),
                'Z'=>self::mul_modp($F,$G), 'T'=>self::mul_modp($E,$H)];
    }

    private static function point_double(array $P): array {
        $A = self::mul_modp($P['X'], $P['X']); $B = self::mul_modp($P['Y'], $P['Y']);
        $C = self::mul_modp('2', self::mul_modp($P['Z'], $P['Z']));
        $D = self::sub_modp(self::ZERO, $A);
        $E = self::sub_modp(self::sub_modp(self::mul_modp(self::add_modp($P['X'],$P['Y']),
            self::add_modp($P['X'],$P['Y'])), $A), $B);
        $G = self::add_modp($D, $B); $F = self::sub_modp($G, $C); $H = self::sub_modp($D, $B);
        return ['X'=>self::mul_modp($E,$F), 'Y'=>self::mul_modp($G,$H),
                'Z'=>self::mul_modp($F,$G), 'T'=>self::mul_modp($E,$H)];
    }

    private static function to_affine(array $P): array {
        $Zi = self::inv_modp($P['Z']);
        return [self::mul_modp($P['X'],$Zi), self::mul_modp($P['Y'],$Zi)];
    }

    private static function modp(string $a): string { $a=bcmod($a,self::P_DEC); if(bccomp($a,'0')<0) $a=bcadd($a,self::P_DEC); return $a; }
    private static function add_modp(string $a, string $b): string { return bcmod(bcadd($a,$b,0),self::P_DEC); }
    private static function sub_modp(string $a, string $b): string { $d=bcmod(bcsub($a,$b,0),self::P_DEC); if(bccomp($d,'0')<0) $d=bcadd($d,self::P_DEC); return $d; }
    private static function mul_modp(string $a, string $b): string { return bcmod(bcmul($a,$b,0),self::P_DEC); }

    private static function inv_modp(string $a): string {
        $t='0'; $newt='1'; $r=self::P_DEC; $newr=self::modp($a);
        while ($newr!=='0') {
            $q=bcdiv($r,$newr,0);
            $tmp=$newt; $newt=bcsub($t,bcmul($q,$newt,0),0); $t=$tmp;
            $tmp=$newr; $newr=bcsub($r,bcmul($q,$newr,0),0); $r=$tmp;
        }
        if ($r!=='1') throw new \RuntimeException('Not invertible');
        if (bccomp($t,'0')<0) $t=bcadd($t,self::P_DEC);
        return bcmod($t,self::P_DEC);
    }

    private static function dec_to_le(string $dec, int $len): string {
        $bytes=[]; $n=self::modp($dec);
        for($i=0;$i<$len;$i++){ $bytes[]=chr((int)bcmod($n,'256')); $n=bcdiv($n,'256',0); }
        return implode('',$bytes);
    }
}
