<?php
/**
 * RFC 6238 TOTP (and its RFC 4226 HOTP base), plus RFC 4648 base32.
 *
 * Deliberately dependency-free (no Composer package) so it works as a plain
 * mu-plugin file. Verified against the official RFC 6238 Appendix B test
 * vectors during development (SHA1, 30s period, 6-digit truncation of the
 * published 8-digit vectors).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QuantumAI_TOTP {

	const SECRET_BYTES = 20; // 160 bits.
	const DIGITS       = 6;
	const PERIOD       = 30; // seconds
	const WINDOW       = 1;  // allow ±1 step (±30s) of clock drift

	const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	public static function generate_secret() {
		return self::base32_encode( random_bytes( self::SECRET_BYTES ) );
	}

	public static function provisioning_uri( $secret, $label, $issuer ) {
		$params = array(
			'secret'    => $secret,
			'issuer'    => $issuer,
			'algorithm' => 'SHA1',
			'digits'    => self::DIGITS,
			'period'    => self::PERIOD,
		);

		return 'otpauth://totp/' . rawurlencode( $issuer . ':' . $label ) . '?' . http_build_query( $params, '', '&' );
	}

	/**
	 * @param string $secret Base32-encoded secret.
	 * @param string $code   User-submitted code (any whitespace is stripped).
	 */
	public static function verify( $secret, $code, $window = self::WINDOW ) {
		$code = preg_replace( '/\s+/', '', (string) $code );

		if ( ! preg_match( '/^\d{' . self::DIGITS . '}$/', $code ) ) {
			return false;
		}

		$counter = (int) floor( time() / self::PERIOD );

		for ( $i = -$window; $i <= $window; $i++ ) {
			if ( hash_equals( self::hotp( $secret, $counter + $i ), $code ) ) {
				return true;
			}
		}

		return false;
	}

	private static function hotp( $secret, $counter ) {
		$key  = self::base32_decode( $secret );
		$bin  = pack( 'N2', 0, $counter ); // 8-byte big-endian counter
		$hash = hash_hmac( 'sha1', $bin, $key, true );

		$offset    = ord( $hash[19] ) & 0xf;
		$truncated = ( ( ord( $hash[ $offset ] ) & 0x7f ) << 24 )
			| ( ( ord( $hash[ $offset + 1 ] ) & 0xff ) << 16 )
			| ( ( ord( $hash[ $offset + 2 ] ) & 0xff ) << 8 )
			| ( ord( $hash[ $offset + 3 ] ) & 0xff );

		$code = $truncated % ( 10 ** self::DIGITS );

		return str_pad( (string) $code, self::DIGITS, '0', STR_PAD_LEFT );
	}

	public static function base32_encode( $data ) {
		if ( '' === $data ) {
			return '';
		}

		$binary_string = '';
		foreach ( str_split( $data ) as $char ) {
			$binary_string .= str_pad( decbin( ord( $char ) ), 8, '0', STR_PAD_LEFT );
		}

		$chunks = str_split( $binary_string, 5 );
		$last   = count( $chunks ) - 1;
		if ( strlen( $chunks[ $last ] ) < 5 ) {
			$chunks[ $last ] = str_pad( $chunks[ $last ], 5, '0', STR_PAD_RIGHT );
		}

		$encoded = '';
		foreach ( $chunks as $chunk ) {
			$encoded .= self::ALPHABET[ bindec( $chunk ) ];
		}

		$pad_length = ( 8 - ( strlen( $encoded ) % 8 ) ) % 8;

		return $encoded . str_repeat( '=', $pad_length );
	}

	public static function base32_decode( $b32 ) {
		$b32 = rtrim( strtoupper( trim( $b32 ) ), '=' );

		if ( '' === $b32 ) {
			return '';
		}

		$binary_string = '';
		foreach ( str_split( $b32 ) as $char ) {
			$pos = strpos( self::ALPHABET, $char );
			if ( false === $pos ) {
				continue; // skip stray whitespace/formatting a user might paste
			}
			$binary_string .= str_pad( decbin( $pos ), 5, '0', STR_PAD_LEFT );
		}

		$bytes = str_split( $binary_string, 8 );
		$data  = '';
		foreach ( $bytes as $byte ) {
			if ( strlen( $byte ) < 8 ) {
				continue; // trailing partial byte, discard per RFC 4648
			}
			$data .= chr( bindec( $byte ) );
		}

		return $data;
	}
}
