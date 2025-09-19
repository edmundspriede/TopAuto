<?php

function jet_smart_filters_woo_prices_lookup( $args = array() ) {
	global $wpdb;

	if ( ! function_exists( 'wc' ) ) {
		return false;
	}

	$wc_query = wc()->query->get_main_query();
	$tax_query = array();

	if ( $wc_query ) {
		$wc_queried_object = $wc_query->queried_object;

		if ( ! empty( $wc_queried_object->taxonomy ) && ! empty( $wc_queried_object->term_id ) ) {
			$tax_query[] = array(
				'taxonomy' => $wc_queried_object->taxonomy,
				'terms'    => array( $wc_queried_object->term_id ),
				'field'    => 'term_id',
			);
		}
	}

	$tax_query = new WP_Tax_Query( $tax_query );
	$tax_query_sql = $tax_query->get_sql( $wpdb->posts, 'ID' );

	$price_table = $wpdb->prefix . 'wc_product_meta_lookup';

	$sql  = "SELECT
				MIN(FLOOR(lookup.min_price)) AS min,
				MAX(CEILING(lookup.max_price)) AS max
			FROM {$wpdb->posts} ";

	$sql .= " INNER JOIN {$price_table} AS lookup ON {$wpdb->posts}.ID = lookup.product_id " . $tax_query_sql['join'];

	$sql .= " WHERE {$wpdb->posts}.post_type IN ('" . implode( "','", array_map( 'esc_sql', apply_filters( 'woocommerce_price_filter_post_type', array( 'product' ) ) ) ) . "')
		AND {$wpdb->posts}.post_status = 'publish'
		AND lookup.min_price IS NOT NULL ";

	$sql .= $tax_query_sql['where'];

	if ( $wc_query ) {
		$search = WC_Query::get_main_search_query_sql();
		$search = apply_filters( 'jet-smart-filters/range-filter/search-query', $search, 'woo_prices' );
	} else {
		$search = false;
	}

	if ( $search ) {
		$sql .= ' AND ' . $search;
	}

	$price = $wpdb->get_row( $sql, ARRAY_A );

	if ( class_exists( 'woocommerce_wpml' ) ){
		$price['min'] = apply_filters( 'wcml_raw_price_amount', floatval( $price['min'] ) );
		$price['max'] = apply_filters( 'wcml_raw_price_amount', floatval( $price['max'] ) );
	}
	
	return $price; // WPCS: unprepared SQL ok.
}

add_filter( 'jet-smart-filters/range/source-callbacks', function( $callbacks ) {
	$callbacks['jet_smart_filters_woo_prices_lookup'] = __( 'WooCommerce min/max prices from lookup', 'jet-smart-filters' );

	return $callbacks;
} );



//Order by price from lookup table
add_filter( 'jet-engine/query-builder/types/posts-query/args', function( $args, $query ) {
	if ( false !== strpos( $query->name, '--order-by-price' ) ) {
		$args['jet_order_by_price'] = 'ASC';

		if ( isset( $args['orderby']['meta_value_num'] ) && $args['meta_key'] === '_price' ) {
			$args['jet_order_by_price'] = $args['orderby']['meta_value_num'];

			unset( $args['orderby']['meta_value_num'] );
			unset( $args['meta_key'] );
		}

	}

	if ( isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ) {
		foreach ( $args['meta_query'] as $k => $meta_query ) {
			if ( isset( $meta_query['key'] ) && $meta_query['key'] === '_price' ) {
				$args['jet_filter_by_price'] = $meta_query;

				unset( $args['meta_query'][ $k ] );
			}
		}
	}

	
	return $args;
}, 10, 2 );

add_filter( 'posts_clauses', 'jet_change_order_sql_by_price', 10, 2 );

function jet_change_order_sql_by_price( $clauses, $wp_query ) {
	
	// order clause
	if ( $wp_query->get('jet_order_by_price') ) {
		global $wpdb;

		$clauses['orderby'] = 'wc_product_meta_lookup.min_price ASC';

		$clauses['join'] .= " INNER JOIN {$wpdb->prefix}wc_product_meta_lookup AS wc_product_meta_lookup ON {$wpdb->posts}.ID = wc_product_meta_lookup.product_id";
	}

	//filter clause
	if ( $wp_query->get('jet_filter_by_price') ){
		global $wpdb;

		$filter_by_price = $wp_query->get('jet_filter_by_price');
		if ( isset( $filter_by_price['value'] ) && is_array( $filter_by_price['value'] ) ) {
			$min_price = floatval( $filter_by_price['value'][0] );
			$max_price = floatval( $filter_by_price['value'][1] );

			$clauses['where'] .= $wpdb->prepare(
				" AND wc_product_meta_lookup.min_price BETWEEN %f AND %f",
				$min_price,
				$max_price
			);
		}

		if (strpos($clauses['join'], "{$wpdb->prefix}wc_product_meta_lookup") === false) {
			$clauses['join'] .= " INNER JOIN {$wpdb->prefix}wc_product_meta_lookup AS wc_product_meta_lookup ON {$wpdb->posts}.ID = wc_product_meta_lookup.product_id";
		}
	}

	return $clauses;
}
