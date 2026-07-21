<?php
/**
 * WooCommerce integration helper functions.
 *
 * @package GTM4WP
 * @author Thomas Geiger
 * @copyright 2013- Geiger Tamás e.v. (Thomas Geiger s.e.)
 * @license GNU General Public License, version 3
 */

namespace GTM4WP\Modules\WooCommerce;

use GTM4WP\Ecommerce\Helpers as EcommerceHelpers;

defined( 'ABSPATH' ) || exit;

/**
 * Static helpers ported from integration/ecommerce-generic.php of 1.x.
 *
 * The store-agnostic pieces (business verticals, category/taxonomy readers,
 * Enhanced Conversions hashing, product id prefixing) live in
 * GTM4WP\Ecommerce\Helpers since 2.0 so other store integrations (Easy
 * Digital Downloads) can share them; the constants and methods here delegate
 * to keep this class' public API unchanged for existing consumers.
 */
final class Helpers {

	/**
	 * Valid Google Ads business vertical ids. The labels shown on the
	 * admin page live in the AdminSchema class.
	 *
	 * @var string[]
	 */
	public const BUSINESS_VERTICALS = EcommerceHelpers::BUSINESS_VERTICALS;

	/**
	 * Business verticals that use a different name for their "id" field in
	 * Google Ads dynamic remarketing tagging.
	 *
	 * @var array<string, string>
	 */
	public const BUSINESS_VERTICALS_IDS = EcommerceHelpers::BUSINESS_VERTICALS_IDS;

	/**
	 * Name of the first-party cookie that carries GA4 list attribution
	 * (item_list_name / item_list_id keyed by product id) across the funnel
	 * (#405). Written client-side by the WooCommerce tracker on a select_item
	 * list click; must match the literal used in js/frontend/gtm4wp-woocommerce.js.
	 */
	public const LIST_ATTRIBUTION_COOKIE = 'gtm4wp_item_list_attr';

	/**
	 * Hard caps on the list-attribution cookie so a crafted or bloated cookie
	 * can never make the reader do unbounded work: entries beyond the limit are
	 * dropped and an oversized cookie is ignored wholesale.
	 */
	public const LIST_ATTRIBUTION_MAX_ENTRIES      = 20;
	public const LIST_ATTRIBUTION_COOKIE_MAX_BYTES = 4096;

	/**
	 * Name of the short-lived, JS-readable event cookie the cache-safe data layer
	 * (issue #398, Phase 3) sets when a WooCommerce one-shot event is queued in the
	 * session — a product re-added to the cart ("Undo") or a placed order awaiting
	 * its reliable-purchase fallback. Its mere presence tells the client runtime to
	 * fetch the session endpoint on the next page; the client fires the event once,
	 * de-dupes it and then clears this cookie. An anonymous visitor on a cached page,
	 * who never has it, never fetches. Must match the literal the client clears in
	 * js/frontend/gtm4wp-visitor-data.js and the cookie_gate declared in
	 * PageDataLayer::declare_visitor_scoped_fields().
	 */
	public const ONESHOT_EVENT_COOKIE = 'gtm4wp_woo_event';

	/**
	 * Flags that a WooCommerce one-shot event is pending for this session by
	 * setting the short-lived event cookie (self::ONESHOT_EVENT_COOKIE) — but only
	 * when the cache-safe data layer is on, since that is the only mode in which the
	 * one-shots are delivered client-side (otherwise they render server-side as
	 * before and no cookie is needed). Called from the same hooks that seed the
	 * session markers (ListTracking::cart_item_restored,
	 * PurchaseTracking::remember_order). Skipped silently once headers are sent
	 * (a cookie cannot be set then) and never lands on a cacheable response, because
	 * those hooks run only on non-cached cart/checkout requests.
	 *
	 * The cookie is deliberately NOT HttpOnly (the client must read it) and carries
	 * no visitor value — only the fact that a fetch is due. The client clears it
	 * after delivery; the 2-day expiry only bounds the case where delivery never
	 * happened, and comfortably covers a WooCommerce session.
	 *
	 * @param bool $cache_safe_enabled Whether GTM4WP_OPTION_CACHE_SAFE_DATALAYER is on.
	 * @return void
	 */
	public static function flag_oneshot_event( bool $cache_safe_enabled ): void {
		if ( ! $cache_safe_enabled || headers_sent() ) {
			return;
		}

		setcookie(
			self::ONESHOT_EVENT_COOKIE,
			'1',
			array(
				'expires'  => time() + ( 2 * DAY_IN_SECONDS ),
				'path'     => '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);

		// Reflect it into $_COOKIE so any later same-request read sees it set.
		$_COOKIE[ self::ONESHOT_EVENT_COOKIE ] = '1';
	}

	/**
	 * Replace only the first occurrence of the search string with the replacement string.
	 *
	 * @param string $search The value being searched for, otherwise known as the needle.
	 * @param string $replace The replacement value that replaces found search values.
	 * @param string $subject The string being searched and replaced on, otherwise known as the haystack.
	 * @return string This function returns a string with the replaced values.
	 */
	public static function str_replace_first( string $search, string $replace, string $subject ): string {
		$search = '/' . preg_quote( $search, '/' ) . '/';

		return (string) preg_replace( $search, $replace, $subject, 1 );
	}

	/**
	 * Takes a product ID and returns a string that has a prefix appended.
	 *
	 * This is needed in cases where the generated feed has IDs with some sort of constant prefix and
	 * tracking needs to align with this ID in order for dynamic remarketing to work properly.
	 *
	 * @param int|string $product_id A product ID that has to be prefixed.
	 * @param string     $prefix The prefix that needs to be added in front of the product ID.
	 * @return int|string The product ID with the prefix string.
	 */
	public static function prefix_productid( $product_id, string $prefix ) {
		return EcommerceHelpers::prefix_productid( $product_id, $prefix );
	}

	/**
	 * Per-unit display price for a WooCommerce cart line, taken from the line
	 * totals WooCommerce has already calculated (line_subtotal / line_subtotal_tax)
	 * instead of recomputing wc_get_price_to_display() per item. That call is
	 * expensive in the cart/checkout context and, run once per cart item, caused
	 * memory exhaustion on carts after a WooCommerce update (#436).
	 *
	 * Returns null when the line totals are not available (e.g. before the cart is
	 * calculated), so the caller can fall back to wc_get_price_to_display().
	 *
	 * @param array<string, mixed> $cart_item_data A WooCommerce cart item.
	 * @param bool                 $include_tax    Whether to include tax (the shop's price-display setting).
	 * @return float|null The per-unit display price rounded to 2 decimals, or null when it cannot be derived.
	 */
	public static function cart_line_display_price( array $cart_item_data, bool $include_tax ): ?float {
		if ( ! isset( $cart_item_data['line_subtotal'] ) ) {
			return null;
		}

		$quantity = (float) ( $cart_item_data['quantity'] ?? 0 );
		if ( $quantity <= 0 ) {
			return null;
		}

		$line = (float) $cart_item_data['line_subtotal'];
		if ( $include_tax ) {
			$line += (float) ( $cart_item_data['line_subtotal_tax'] ?? 0 );
		}

		return round( $line / $quantity, 2 );
	}

	/**
	 * Per-unit discount for a WooCommerce cart line: the gap between the
	 * pre-discount subtotal (line_subtotal) and the post-discount total
	 * (line_total), on the same tax basis as cart_line_display_price(), divided
	 * by the line quantity. Used to add GA4's per-item `discount` field where a
	 * coupon or sale reduced the line (#348).
	 *
	 * Returns null when the totals are not available or when there is no discount
	 * (≤ 0), so the caller can simply omit the field on undiscounted lines rather
	 * than emit a 0.
	 *
	 * @param array<string, mixed> $cart_item_data A WooCommerce cart item.
	 * @param bool                 $include_tax    Whether to include tax (the shop's price-display setting).
	 * @return float|null The per-unit discount rounded to 2 decimals, or null when there is none.
	 */
	public static function cart_line_discount( array $cart_item_data, bool $include_tax ): ?float {
		if ( ! isset( $cart_item_data['line_subtotal'], $cart_item_data['line_total'] ) ) {
			return null;
		}

		$quantity = (float) ( $cart_item_data['quantity'] ?? 0 );
		if ( $quantity <= 0 ) {
			return null;
		}

		$subtotal = (float) $cart_item_data['line_subtotal'];
		$total    = (float) $cart_item_data['line_total'];
		if ( $include_tax ) {
			$subtotal += (float) ( $cart_item_data['line_subtotal_tax'] ?? 0 );
			$total    += (float) ( $cart_item_data['line_total_tax'] ?? 0 );
		}

		$discount = round( ( $subtotal - $total ) / $quantity, 2 );
		if ( $discount <= 0 ) {
			return null;
		}

		return $discount;
	}

	/**
	 * Reads and validates the first-party list-attribution cookie (#405) into a
	 * map of product id => array( item_list_name, item_list_id ). The cookie is
	 * untrusted client input, so every part is sanitized here: the id via absint,
	 * the list name via sanitize_text_field and the id via sanitize_title. The
	 * sanitized values are returned RAW (not entity-encoded) so the downstream
	 * wp_json_encode() dataLayer sink can escape them once and correctly. A
	 * malformed, non-JSON or oversized cookie yields an empty map, and no more
	 * than LIST_ATTRIBUTION_MAX_ENTRIES entries are ever processed.
	 *
	 * @return array<int, array{item_list_name: string, item_list_id: string}>
	 */
	public static function read_item_list_cookie(): array {
		if ( ! isset( $_COOKIE[ self::LIST_ATTRIBUTION_COOKIE ] ) ) {
			return array();
		}

		// The raw value is a JSON container, not a value used at any output sink; it
		// is json_decode'd below and every extracted field is individually sanitized
		// (absint on ids, sanitize_text_field on names, sanitize_title on ids). A
		// blanket sanitizer here would corrupt valid multi-entry JSON, so it is
		// unslashed only and each field is sanitized after decoding.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = wp_unslash( $_COOKIE[ self::LIST_ATTRIBUTION_COOKIE ] );
		if ( ! is_string( $raw ) || strlen( $raw ) > self::LIST_ATTRIBUTION_COOKIE_MAX_BYTES ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$map   = array();
		$count = 0;
		foreach ( $decoded as $product_id => $entry ) {
			if ( $count >= self::LIST_ATTRIBUTION_MAX_ENTRIES ) {
				break;
			}

			$pid = absint( $product_id );
			if ( $pid <= 0 || ! is_array( $entry ) ) {
				continue;
			}

			$name = isset( $entry['item_list_name'] ) ? sanitize_text_field( (string) $entry['item_list_name'] ) : '';
			if ( '' === $name ) {
				continue;
			}

			$id = ( isset( $entry['item_list_id'] ) && '' !== $entry['item_list_id'] )
				? sanitize_title( (string) $entry['item_list_id'] )
				: sanitize_title( $name );

			$map[ $pid ] = array(
				'item_list_name' => $name,
				'item_list_id'   => $id,
			);
			++$count;
		}

		return $map;
	}

	/**
	 * Given a category ID, this function returns the full path to this category separated with the / character.
	 *
	 * @param int    $category_id The ID of the category that needs to be scanned for parents.
	 * @param string $category_taxonomy The name of the WordPress taxonomy where the category ID needs to be searched.
	 * @return string The category path. An example output can be: Home/Clothing/Toddlers.
	 */
	public static function get_product_category_hierarchy( $category_id, string $category_taxonomy = 'product_cat' ): string {
		return EcommerceHelpers::get_product_category_hierarchy( $category_id, $category_taxonomy );
	}

	/**
	 * Given a product ID, this function will return the first assigned category of the product.
	 * Uses the "primary category" of Yoast SEO or Rank Math when available.
	 *
	 * @param int     $product_id A product ID whose first assigned category has to be returned.
	 * @param boolean $fullpath Set this to true if you need to query the full path including parent categories.
	 * @param string  $category_taxonomy The name of the WordPress taxonomy where the category ID needs to be searched.
	 * @return string The first category name of the product. Includes parent category names if $fullpath is true.
	 */
	public static function get_product_category( $product_id, bool $fullpath = false, string $category_taxonomy = 'product_cat' ): string {
		return EcommerceHelpers::get_product_category( $product_id, $fullpath, $category_taxonomy );
	}

	/**
	 * Given a product ID, this function returns the assigned value of a custom taxonomy like the brand name.
	 *
	 * @param int    $product_id A WooCommerce product ID whose taxonomy association needs to be queried.
	 * @param string $taxonomy The taxonomy slug for which to retrieve terms.
	 * @return string Returns the first assigned taxonomy value of the given WooCommerce product ID.
	 */
	public static function get_product_term( $product_id, string $taxonomy ): string {
		return EcommerceHelpers::get_product_term( $product_id, $taxonomy );
	}

	/**
	 * Given a Google Business vertical ID, this function returns the name of the "ID" field in tagging
	 * Google Ads dynamic remarketing. This is "id" in most cases, but sometimes "destination".
	 *
	 * @param string $vertical_id The Google Business vertical ID (like retail, flights, etc.).
	 * @return string The name of the "ID" field for tagging.
	 */
	public static function get_gads_product_id_variable_name( string $vertical_id ): string {
		return EcommerceHelpers::get_gads_product_id_variable_name( $vertical_id );
	}

	/**
	 * Returns the result of normalizing and hashing any data.
	 *
	 * @link https://developers.google.com/google-ads/api/docs/conversions/enhanced-conversions/web#php
	 *
	 * @param string $hash_algorithm the hash algorithm to use.
	 * @param string $value the value to normalize and hash.
	 * @param bool   $trim_intermediate_spaces whether to remove all spaces before hashing (true) or only leading and trailing spaces (false).
	 * @return string the normalized and hashed value.
	 */
	public static function normalize_and_hash( string $hash_algorithm, string $value, bool $trim_intermediate_spaces ): string {
		return EcommerceHelpers::normalize_and_hash( $hash_algorithm, $value, $trim_intermediate_spaces );
	}

	/**
	 * Returns the result of normalizing and hashing an email address. For this use case, Google
	 * Ads requires removal of any '.' characters preceding "gmail.com" or "googlemail.com".
	 *
	 * @link https://developers.google.com/google-ads/api/docs/conversions/enhanced-conversions/web#php
	 *
	 * @param string $hash_algorithm the hash algorithm to use.
	 * @param string $email_address the email address to normalize and hash.
	 * @return string the normalized and hashed email address.
	 */
	public static function normalize_and_hash_email_address( string $hash_algorithm, string $email_address ): string {
		return EcommerceHelpers::normalize_and_hash_email_address( $hash_algorithm, $email_address );
	}
}
