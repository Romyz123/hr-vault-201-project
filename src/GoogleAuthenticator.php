<?php
// ========================================================================
// [MHI COMPLIANCE] Offline Google Authenticator (TOTP) Core
// ========================================================================

class GoogleAuthenticator
{
    public static function generateSecret($length = 16)
    {
        $b32 = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
        $s = "";
        for ($i = 0; $i < $length; $i++) {
            $s .= $b32[random_int(0, 31)];
        }
        return $s;
    }

    public static function getCode($secret, $timeSlice = null)
    {
        if ($timeSlice === null) {
            $timeSlice = floor(time() / 30);
        }

        $secretKey = self::base32Decode($secret);
        if ($secretKey === false) {
            throw new InvalidArgumentException('Invalid TOTP secret provided.');
        }

        $time = pack('N*', 0) . pack('N*', $timeSlice);
        $hm = hash_hmac('SHA1', $time, $secretKey, true);
        $offset = ord(substr($hm, -1)) & 0x0F;
        $hashPart = substr($hm, $offset, 4);
        $value = unpack('N', $hashPart);
        $value = $value[1] & 0x7FFFFFFF;

        return str_pad($value % 1000000, 6, '0', STR_PAD_LEFT);
    }

    public static function verifyCode($secret, $code, $discrepancy = 1)
    {
        if (empty($secret) || empty($code)) {
            return false;
        }

        $currentTimeSlice = floor(time() / 30);
        try {
            for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
                if (hash_equals(self::getCode($secret, $currentTimeSlice + $i), (string)$code)) {
                    return true;
                }
            }
        } catch (Exception $e) {
            // Invalid secret or internal error; treat as verification failure
            error_log('TOTP verification failed: ' . $e->getMessage());
        }

        return false;
    }

    public static function getOtpauthUri($name, $secret, $title = 'TESP_HR_Vault')
    {
        $encName = rawurlencode($name);
        $encTitle = rawurlencode($title);
        return "otpauth://totp/{$encTitle}:{$encName}?secret={$secret}&issuer={$encTitle}";
    }

    public static function getQRCodeDataUri($name, $secret, $title = 'TESP_HR_Vault')
    {
        $uri = self::getOtpauthUri($name, $secret, $title);

        return [
            'uri' => $uri,
            'secret' => $secret
        ];
    }

    private static function base32Decode($secret)
    {
        if (empty($secret)) {
            return false;
        }

        $b32 = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
        $secret = strtoupper($secret);
        $l = strlen($secret);
        $n = 0;
        $j = 0;
        $dec = '';

        for ($i = 0; $i < $l; $i++) {
            $idx = strpos($b32, $secret[$i]);
            if ($idx === false) {
                throw new InvalidArgumentException('Invalid Base32 character: ' . $secret[$i]);
            }
            $n = ($n << 5) + $idx;
            $j += 5;
            if ($j >= 8) {
                $j -= 8;
                $dec .= chr(($n & (0xFF << $j)) >> $j);
            }
        }

        return $dec;
    }
}
