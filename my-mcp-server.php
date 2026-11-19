<?php
/**
 * Plugin Name: My MCP Server (key auth)
 * Description: Exposes a keyed MCP endpoint via the WordPress MCP Adapter.
 *
 * Endpoint:  https://YOUR-SITE/wp-json/my-plugin/mcp
 * Auth:      header  X-API-Key: <your key>   (or  ?key=<your key>  in the URL)
 *
 * Set your key in wp-config.php:  define( 'MY_MCP_KEY', 'long-random-secret' );
 * Until you do, it falls back to the inline default below.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve the expected API key.
 * Priority: MY_MCP_KEY constant (wp-config) > stored option.
 */
function my_mcp_expected_key() {
	if ( defined( 'MY_MCP_KEY' ) && MY_MCP_KEY ) {
		return (string) MY_MCP_KEY;
	}
	return (string) get_option( 'my_mcp_api_key', '' );
}

/**
 * Make sure a key exists (auto-generate a strong one on first use).
 */
function my_mcp_maybe_generate_key() {
	if ( defined( 'MY_MCP_KEY' ) && MY_MCP_KEY ) {
		return; // managed in wp-config, nothing to store
	}
	if ( '' === (string) get_option( 'my_mcp_api_key', '' ) ) {
		update_option( 'my_mcp_api_key', wp_generate_password( 44, false ) );
	}
}
register_activation_hook( __FILE__, 'my_mcp_maybe_generate_key' );

/* ------------------------------------------------------------------ *
 * Admin screen: Settings -> MCP Server
 * ------------------------------------------------------------------ */
add_action( 'admin_menu', function () {
	add_options_page( 'MCP Server', 'MCP Server', 'manage_options', 'my-mcp-server', 'my_mcp_settings_page' );
} );

function my_mcp_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$locked = defined( 'MY_MCP_KEY' ) && MY_MCP_KEY;
	$notice = '';

	if ( ! $locked && isset( $_POST['my_mcp_action'] ) && check_admin_referer( 'my_mcp_save' ) ) {
		$action = sanitize_text_field( wp_unslash( $_POST['my_mcp_action'] ) );
		if ( 'generate' === $action ) {
			update_option( 'my_mcp_api_key', wp_generate_password( 44, false ) );
			$notice = 'A new key was generated. Update your connected clients.';
		} elseif ( 'save' === $action ) {
			$k = sanitize_text_field( wp_unslash( $_POST['my_mcp_api_key'] ?? '' ) );
			if ( strlen( $k ) < 16 ) {
				$notice = 'error:Key must be at least 16 characters.';
			} else {
				update_option( 'my_mcp_api_key', $k );
				$notice = 'Key saved.';
			}
		}
	}

	my_mcp_maybe_generate_key();
	$key      = my_mcp_expected_key();
	$endpoint = site_url( '/wp-json/my-plugin/mcp' );
	$full_url = $endpoint . '?key=' . rawurlencode( $key );

	echo '<div class="wrap"><h1>MCP Server</h1>';

	if ( $notice ) {
		$is_err = str_starts_with( $notice, 'error:' );
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			$is_err ? 'error' : 'success',
			esc_html( $is_err ? substr( $notice, 6 ) : $notice )
		);
	}

	echo '<table class="form-table" role="presentation"><tbody>';

	// Endpoint
	printf(
		'<tr><th scope="row">Endpoint URL</th><td><input type="text" readonly class="large-text code" value="%s" onclick="this.select()"></td></tr>',
		esc_attr( $endpoint )
	);

	// Key
	echo '<tr><th scope="row">API Key</th><td>';
	if ( $locked ) {
		printf(
			'<input type="text" readonly class="large-text code" value="%s" onclick="this.select()"><p class="description">Key is set in <code>wp-config.php</code> via <code>MY_MCP_KEY</code>; edit it there to change it.</p></td></tr>',
			esc_attr( $key )
		);
		echo '</tbody></table>';
	} else {
		echo '<form method="post">';
		wp_nonce_field( 'my_mcp_save' );
		printf(
			'<input type="text" name="my_mcp_api_key" class="large-text code" value="%s" onclick="this.select()">',
			esc_attr( $key )
		);
		echo '<p class="description">Type your own key (min 16 chars) and Save, or generate a strong random one.</p>';
		echo '<p>';
		echo '<button type="submit" name="my_mcp_action" value="save" class="button button-primary">Save key</button> ';
		echo '<button type="submit" name="my_mcp_action" value="generate" class="button" onclick="return confirm(\'Generate a new key? The current key will stop working until you update your clients.\')">Generate new key</button>';
		echo '</p></form></td></tr>';
		echo '</tbody></table>';
	}

	// Connection URL + examples
	echo '<h2>Connect</h2>';
	echo '<p>Full connection URL (key in the URL — works with claude.ai web connectors and any MCP client):</p>';
	printf(
		'<input type="text" readonly class="large-text code" value="%s" onclick="this.select()">',
		esc_attr( $full_url )
	);
	echo '<p style="margin-top:12px">Or send the key as a header instead of in the URL:</p>';
	printf( '<pre class="code" style="padding:12px;background:#f6f7f7;overflow:auto">X-API-Key: %s</pre>', esc_html( $key ) );

	echo '</div>';
}

/**
 * Assign categories and/or tags (by name, created if missing) to a post.
 */
function my_mcp_assign_terms( $post_id, $input ) {
	if ( isset( $input['categories'] ) && is_array( $input['categories'] ) ) {
		$ids = [];
		foreach ( $input['categories'] as $name ) {
			$name = trim( (string) $name );
			if ( '' === $name ) {
				continue;
			}
			$term = term_exists( $name, 'category' );
			if ( ! $term ) {
				$term = wp_insert_term( $name, 'category' );
			}
			if ( ! is_wp_error( $term ) ) {
				$ids[] = (int) $term['term_id'];
			}
		}
		if ( $ids ) {
			wp_set_post_terms( $post_id, $ids, 'category' );
		}
	}
	if ( isset( $input['tags'] ) && is_array( $input['tags'] ) ) {
		$tags = array_filter( array_map( 'trim', array_map( 'strval', $input['tags'] ) ) );
		if ( $tags ) {
			wp_set_post_terms( $post_id, $tags, 'post_tag' ); // names; created if missing
		}
	}
}

/**
 * Parse a date string (interpreted in the site's timezone) into the local and
 * GMT 'Y-m-d H:i:s' values WordPress stores, plus whether it is in the future.
 *
 * @return array{local:string,gmt:string,future:bool}|WP_Error|null
 */
function my_mcp_parse_date( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return null;
	}
	try {
		$dt = new DateTime( str_replace( 'T', ' ', $raw ), wp_timezone() );
	} catch ( Exception $e ) {
		return new WP_Error( 'bad_date', 'Could not parse date: ' . $raw );
	}
	$local = $dt->format( 'Y-m-d H:i:s' );
	$utc   = ( clone $dt )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	return [ 'local' => $local, 'gmt' => $utc, 'future' => $dt->getTimestamp() > time() ];
}

/**
 * Write SEO title / description / focus keyword to whichever SEO plugin is
 * active (Yoast and/or Rank Math). Falls back to _mcp_* meta if neither is found.
 *
 * @return array|null  Which targets were written, or null if no SEO fields given.
 */
function my_mcp_set_seo( $post_id, $input ) {
	$title = $input['seo_title'] ?? null;
	$desc  = $input['seo_description'] ?? null;
	$kw    = $input['focus_keyword'] ?? null;
	if ( null === $title && null === $desc && null === $kw ) {
		return null;
	}
	$written = [];

	if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
		if ( null !== $title ) { update_post_meta( $post_id, 'rank_math_title', wp_strip_all_tags( (string) $title ) ); }
		if ( null !== $desc )  { update_post_meta( $post_id, 'rank_math_description', wp_strip_all_tags( (string) $desc ) ); }
		if ( null !== $kw )    { update_post_meta( $post_id, 'rank_math_focus_keyword', sanitize_text_field( (string) $kw ) ); }
		$written[] = 'rank_math';
	}
	if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
		if ( null !== $title ) { update_post_meta( $post_id, '_yoast_wpseo_title', wp_strip_all_tags( (string) $title ) ); }
		if ( null !== $desc )  { update_post_meta( $post_id, '_yoast_wpseo_metadesc', wp_strip_all_tags( (string) $desc ) ); }
		if ( null !== $kw )    { update_post_meta( $post_id, '_yoast_wpseo_focuskw', sanitize_text_field( (string) $kw ) ); }
		$written[] = 'yoast';
	}
	if ( empty( $written ) ) {
		if ( null !== $title ) { update_post_meta( $post_id, '_mcp_seo_title', wp_strip_all_tags( (string) $title ) ); }
		if ( null !== $desc )  { update_post_meta( $post_id, '_mcp_seo_description', wp_strip_all_tags( (string) $desc ) ); }
		if ( null !== $kw )    { update_post_meta( $post_id, '_mcp_focus_keyword', sanitize_text_field( (string) $kw ) ); }
		$written[] = 'none-detected (stored as _mcp_* meta)';
	}
	return $written;
}

/**
 * Log the request in as an admin when a valid key is present (header or ?key=).
 */
add_filter( 'determine_current_user', function ( $user_id ) {
	if ( ! empty( $user_id ) ) {
		return $user_id;
	}
	$key = $_SERVER['HTTP_X_API_KEY'] ?? ( $_GET['key'] ?? '' );
	// Reject non-string (e.g. crafted ?key[]=...) so hash_equals() never gets an
	// array and fatals the whole request with a TypeError.
	if ( ! is_string( $key ) || '' === $key || ! hash_equals( my_mcp_expected_key(), $key ) ) {
		return $user_id;
	}
	$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
	return $admins ? (int) $admins[0] : $user_id;
}, 20 );

/* ------------------------------------------------------------------ *
 * Abilities. 'category' MUST be a registered ability category.
 * This site has: site, user, mcp-adapter. We use 'site' for all.
 * ------------------------------------------------------------------ */
add_action( 'wp_abilities_api_init', function () {

	$cat = 'site';

	// --- get site info -------------------------------------------------
	wp_register_ability( 'my-plugin/get-site-info', [
		'label'            => 'Get Site Information',
		'description'      => 'Returns site name, URL, tagline, WordPress version and post/page counts.',
		'category'         => $cat,
		'input_schema'     => [ 'type' => 'object', 'properties' => [] ],
		'execute_callback' => function () {
			return [
				'site_name'  => get_bloginfo( 'name' ),
				'site_url'   => get_site_url(),
				'tagline'    => get_bloginfo( 'description' ),
				'wp_version' => get_bloginfo( 'version' ),
				'posts'      => (int) wp_count_posts( 'post' )->publish,
				'pages'      => (int) wp_count_posts( 'page' )->publish,
			];
		},
		'permission_callback' => '__return_true',
	] );

	// --- list / search posts ------------------------------------------
	wp_register_ability( 'my-plugin/list-posts', [
		'label'        => 'List / Search Posts',
		'description'   => 'Lists posts, optionally filtered by status, type or a search term.',
		'category'      => $cat,
		'input_schema'  => [
			'type'       => 'object',
			'properties' => [
				'number'    => [ 'type' => 'integer', 'description' => 'How many to return (1-50, default 10).' ],
				'status'    => [ 'type' => 'string', 'description' => 'publish, draft, pending, future, private or any (default publish).' ],
				'post_type' => [ 'type' => 'string', 'description' => 'Post type (default post). e.g. post, page.' ],
				'search'    => [ 'type' => 'string', 'description' => 'Optional keyword to search titles/content.' ],
			],
		],
		'execute_callback' => function ( $input ) {
			$q = new WP_Query( [
				'post_type'      => $input['post_type'] ?? 'post',
				'post_status'    => $input['status'] ?? 'publish',
				'posts_per_page' => min( max( (int) ( $input['number'] ?? 10 ), 1 ), 50 ),
				's'              => $input['search'] ?? '',
				'no_found_rows'  => true,
			] );
			$out = [];
			foreach ( $q->posts as $p ) {
				$out[] = [
					'id'     => $p->ID,
					'title'  => get_the_title( $p ),
					'status' => $p->post_status,
					'type'   => $p->post_type,
					'date'   => $p->post_date,
					'link'   => get_permalink( $p ),
				];
			}
			return [ 'count' => count( $out ), 'posts' => $out ];
		},
		'permission_callback' => '__return_true',
	] );

	// --- get one post (full content) ----------------------------------
	wp_register_ability( 'my-plugin/get-post', [
		'label'        => 'Get Post',
		'description'   => 'Returns the full content and fields of a single post by ID.',
		'category'      => $cat,
		'input_schema'  => [
			'type'       => 'object',
			'required'   => [ 'id' ],
			'properties' => [
				'id' => [ 'type' => 'integer', 'description' => 'Post ID.' ],
			],
		],
		'execute_callback' => function ( $input ) {
			$p = get_post( (int) ( $input['id'] ?? 0 ) );
			if ( ! $p ) {
				return new WP_Error( 'not_found', 'No post with that ID.' );
			}
			return [
				'id'       => $p->ID,
				'title'    => $p->post_title,
				'status'   => $p->post_status,
				'type'     => $p->post_type,
				'date'     => $p->post_date,
				'content'  => $p->post_content,
				'excerpt'  => $p->post_excerpt,
				'link'     => get_permalink( $p ),
				'featured' => get_post_thumbnail_id( $p ) ?: null,
			];
		},
		'permission_callback' => '__return_true',
	] );

	// --- create a post (draft OR published) ---------------------------
	wp_register_ability( 'my-plugin/create-post', [
		'label'        => 'Create Post',
		'description'   => 'Creates a post. Accepts full HTML or Gutenberg block content, categories and tags. Set status to "publish" to publish immediately or "draft" (default) to save a draft.',
		'category'      => $cat,
		'input_schema'  => [
			'type'       => 'object',
			'required'   => [ 'title' ],
			'properties' => [
				'title'      => [ 'type' => 'string', 'description' => 'Post title.' ],
				'content'    => [ 'type' => 'string', 'description' => 'Post body — HTML or Gutenberg block markup.' ],
				'excerpt'    => [ 'type' => 'string', 'description' => 'Optional excerpt.' ],
				'status'          => [ 'type' => 'string', 'description' => 'draft (default), publish, pending or future.' ],
				'date'            => [ 'type' => 'string', 'description' => 'Publish date/time in the site timezone, e.g. "2026-12-01 09:00:00" or ISO 8601. A future date with status=publish schedules the post.' ],
				'post_type'       => [ 'type' => 'string', 'description' => 'Post type (default post).' ],
				'categories'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Category names (created if missing).' ],
				'tags'            => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Tag names (created if missing).' ],
				'seo_title'       => [ 'type' => 'string', 'description' => 'SEO meta title (Yoast / Rank Math).' ],
				'seo_description' => [ 'type' => 'string', 'description' => 'SEO meta description.' ],
				'focus_keyword'   => [ 'type' => 'string', 'description' => 'Primary focus keyword.' ],
			],
		],
		'execute_callback' => function ( $input ) {
			$title = trim( (string) ( $input['title'] ?? '' ) );
			if ( '' === $title ) {
				return new WP_Error( 'missing_title', 'A title is required.' );
			}
			$status = in_array( $input['status'] ?? 'draft', [ 'draft', 'publish', 'pending', 'future' ], true )
				? $input['status'] : 'draft';

			$args = [
				'post_title'   => $title,
				'post_content' => (string) ( $input['content'] ?? '' ),
				'post_excerpt' => (string) ( $input['excerpt'] ?? '' ),
				'post_status'  => $status,
				'post_type'    => $input['post_type'] ?? 'post',
				'post_author'  => get_current_user_id(),
			];

			if ( ! empty( $input['date'] ) ) {
				$d = my_mcp_parse_date( $input['date'] );
				if ( is_wp_error( $d ) ) {
					return $d;
				}
				$args['post_date']     = $d['local'];
				$args['post_date_gmt'] = $d['gmt'];
				if ( $d['future'] && 'publish' === $status ) {
					$args['post_status'] = 'future'; // schedule it
				}
			}

			$post_id = wp_insert_post( $args, true );
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
			my_mcp_assign_terms( $post_id, $input );
			$seo = my_mcp_set_seo( $post_id, $input );

			$final = get_post_status( $post_id );
			return [
				'id'        => $post_id,
				'status'    => $final,
				'scheduled' => 'future' === $final ? get_post_field( 'post_date', $post_id ) : null,
				'link'      => get_permalink( $post_id ),
				'edit_link' => get_edit_post_link( $post_id, 'raw' ),
				'seo'       => $seo,
			];
		},
		'permission_callback' => '__return_true',
	] );

	// --- update a post -------------------------------------------------
	wp_register_ability( 'my-plugin/update-post', [
		'label'        => 'Update Post',
		'description'   => 'Updates fields of an existing post by ID. Only the fields you pass are changed. Supports categories and tags.',
		'category'      => $cat,
		'input_schema'  => [
			'type'       => 'object',
			'required'   => [ 'id' ],
			'properties' => [
				'id'         => [ 'type' => 'integer', 'description' => 'Post ID.' ],
				'title'      => [ 'type' => 'string', 'description' => 'New title.' ],
				'content'    => [ 'type' => 'string', 'description' => 'New body — HTML or block markup.' ],
				'excerpt'    => [ 'type' => 'string', 'description' => 'New excerpt.' ],
				'status'          => [ 'type' => 'string', 'description' => 'New status: draft, publish, pending, future, private.' ],
				'date'            => [ 'type' => 'string', 'description' => 'New publish date/time in the site timezone (schedules when future + status=publish/future).' ],
				'categories'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Replace categories (names, created if missing).' ],
				'tags'            => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Replace tags (names, created if missing).' ],
				'seo_title'       => [ 'type' => 'string', 'description' => 'SEO meta title (Yoast / Rank Math).' ],
				'seo_description' => [ 'type' => 'string', 'description' => 'SEO meta description.' ],
				'focus_keyword'   => [ 'type' => 'string', 'description' => 'Primary focus keyword.' ],
			],
		],
		'execute_callback' => function ( $input ) {
			$id = (int) ( $input['id'] ?? 0 );
			if ( ! get_post( $id ) ) {
				return new WP_Error( 'not_found', 'No post with that ID.' );
			}
			$data = [ 'ID' => $id ];
			if ( isset( $input['title'] ) )   { $data['post_title']   = (string) $input['title']; }
			if ( isset( $input['content'] ) ) { $data['post_content'] = (string) $input['content']; }
			if ( isset( $input['excerpt'] ) ) { $data['post_excerpt'] = (string) $input['excerpt']; }
			if ( isset( $input['status'] ) )  { $data['post_status']  = (string) $input['status']; }

			if ( ! empty( $input['date'] ) ) {
				$d = my_mcp_parse_date( $input['date'] );
				if ( is_wp_error( $d ) ) {
					return $d;
				}
				$data['post_date']     = $d['local'];
				$data['post_date_gmt'] = $d['gmt'];
				if ( $d['future'] && ( ( $input['status'] ?? '' ) === 'publish' ) ) {
					$data['post_status'] = 'future';
				}
			}

			$res = wp_update_post( $data, true );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			my_mcp_assign_terms( $id, $input );
			$seo = my_mcp_set_seo( $id, $input );

			$final = get_post_status( $id );
			return [
				'id'        => $id,
				'status'    => $final,
				'scheduled' => 'future' === $final ? get_post_field( 'post_date', $id ) : null,
				'link'      => get_permalink( $id ),
				'seo'       => $seo,
			];
		},
		'permission_callback' => '__return_true',
	] );

	// --- publish a post ------------------------------------------------
	wp_register_ability( 'my-plugin/publish-post', [
		'label'        => 'Publish Post',
		'description'   => 'Publishes an existing post (sets its status to publish) by ID.',
		'category'      => $cat,
		'input_schema'  => [
			'type'       => 'object',
			'required'   => [ 'id' ],
			'properties' => [
				'id' => [ 'type' => 'integer', 'description' => 'Post ID to publish.' ],
			],
		],
		'execute_callback' => function ( $input ) {
			$id = (int) ( $input['id'] ?? 0 );
			if ( ! get_post( $id ) ) {
				return new WP_Error( 'not_found', 'No post with that ID.' );
			}
			$res = wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ], true );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			return [ 'id' => $id, 'status' => 'publish', 'link' => get_permalink( $id ) ];
		},
		'permission_callback' => '__return_true',
	] );

	// --- delete a post -------------------------------------------------
	wp_register_ability( 'my-plugin/delete-post', [
		'label'        => 'Delete Post',
		'description'   => 'Trashes a post by ID (or permanently deletes it when force=true).',
		'category'      => $cat,
		'input_schema'  => [
			'type'       => 'object',
			'required'   => [ 'id' ],
			'properties' => [
				'id'    => [ 'type' => 'integer', 'description' => 'Post ID.' ],
				'force' => [ 'type' => 'boolean', 'description' => 'true = permanently delete, false = trash (default).' ],
			],
		],
		'execute_callback' => function ( $input ) {
			$id = (int) ( $input['id'] ?? 0 );
			if ( ! get_post( $id ) ) {
				return new WP_Error( 'not_found', 'No post with that ID.' );
			}
			$force = ! empty( $input['force'] );
			$res   = wp_delete_post( $id, $force );
			if ( ! $res ) {
				return new WP_Error( 'delete_failed', 'Could not delete that post.' );
			}
			return [ 'id' => $id, 'deleted' => true, 'permanent' => $force ];
		},
		'permission_callback' => '__return_true',
	] );

	// --- upload media (sideload from URL or base64) -------------------
	wp_register_ability( 'my-plugin/upload-media', [
		'label'        => 'Upload Media',
		'description'   => 'Uploads a file into the Media Library from a public URL (or base64 data) and returns its attachment ID and URL.',
		'category'      => $cat,
		'input_schema'  => [
			'type'       => 'object',
			'properties' => [
				'url'      => [ 'type' => 'string', 'description' => 'Public URL of the file to sideload.' ],
				'data'     => [ 'type' => 'string', 'description' => 'Base64-encoded file contents (alternative to url).' ],
				'filename' => [ 'type' => 'string', 'description' => 'Filename, required with data (e.g. photo.jpg).' ],
				'title'    => [ 'type' => 'string', 'description' => 'Optional media title.' ],
				'alt'      => [ 'type' => 'string', 'description' => 'Optional alt text.' ],
			],
		],
		'execute_callback' => function ( $input ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$filename = (string) ( $input['filename'] ?? '' );
			$tmp      = '';

			if ( ! empty( $input['url'] ) ) {
				$tmp = download_url( esc_url_raw( $input['url'] ) );
				if ( is_wp_error( $tmp ) ) {
					return $tmp;
				}
				if ( '' === $filename ) {
					$filename = basename( (string) wp_parse_url( $input['url'], PHP_URL_PATH ) );
				}
			} elseif ( ! empty( $input['data'] ) ) {
				if ( '' === $filename ) {
					return new WP_Error( 'missing_filename', 'filename is required when using data.' );
				}
				$bytes = base64_decode( $input['data'], true );
				if ( false === $bytes ) {
					return new WP_Error( 'bad_base64', 'data is not valid base64.' );
				}
				$tmp = wp_tempnam( $filename );
				file_put_contents( $tmp, $bytes );
			} else {
				return new WP_Error( 'no_source', 'Provide a url or base64 data.' );
			}

			$file_array = [ 'name' => $filename ?: 'upload.bin', 'tmp_name' => $tmp ];
			$attach_id  = media_handle_sideload( $file_array, 0, $input['title'] ?? null );
			if ( is_wp_error( $attach_id ) ) {
				@unlink( $tmp );
				return $attach_id;
			}
			if ( ! empty( $input['alt'] ) ) {
				update_post_meta( $attach_id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt'] ) );
			}
			return [
				'attachment_id' => $attach_id,
				'url'           => wp_get_attachment_url( $attach_id ),
			];
		},
		'permission_callback' => '__return_true',
	] );

	// --- list categories / tags ---------------------------------------
	wp_register_ability( 'my-plugin/list-terms', [
		'label'        => 'List Categories / Tags',
		'description'   => 'Lists existing categories or tags (id, name, slug, post count). Use this before assigning terms to avoid duplicates.',
		'category'      => $cat,
		'input_schema'  => [
			'type'       => 'object',
			'properties' => [
				'taxonomy' => [ 'type' => 'string', 'description' => 'category (default) or post_tag.' ],
				'search'   => [ 'type' => 'string', 'description' => 'Optional name filter.' ],
				'number'   => [ 'type' => 'integer', 'description' => 'Max to return (1-200, default 100).' ],
			],
		],
		'execute_callback' => function ( $input ) {
			$tax = in_array( $input['taxonomy'] ?? 'category', [ 'category', 'post_tag' ], true )
				? $input['taxonomy'] : 'category';
			$terms = get_terms( [
				'taxonomy'   => $tax,
				'hide_empty' => false,
				'search'     => $input['search'] ?? '',
				'number'     => min( max( (int) ( $input['number'] ?? 100 ), 1 ), 200 ),
			] );
			if ( is_wp_error( $terms ) ) {
				return $terms;
			}
			$out = [];
			foreach ( $terms as $t ) {
				$out[] = [
					'id'    => $t->term_id,
					'name'  => $t->name,
					'slug'  => $t->slug,
					'count' => $t->count,
				];
			}
			return [ 'taxonomy' => $tax, 'count' => count( $out ), 'terms' => $out ];
		},
		'permission_callback' => '__return_true',
	] );

	// --- set featured image -------------------------------------------
	wp_register_ability( 'my-plugin/set-featured-image', [
		'label'        => 'Set Featured Image',
		'description'   => 'Sets a post\'s featured image from an existing attachment ID, or by uploading an image URL.',
		'category'      => $cat,
		'input_schema'  => [
			'type'       => 'object',
			'required'   => [ 'post_id' ],
			'properties' => [
				'post_id'       => [ 'type' => 'integer', 'description' => 'Post ID.' ],
				'attachment_id' => [ 'type' => 'integer', 'description' => 'Existing media attachment ID.' ],
				'image_url'     => [ 'type' => 'string', 'description' => 'Image URL to upload then set (alternative to attachment_id).' ],
			],
		],
		'execute_callback' => function ( $input ) {
			$post_id = (int) ( $input['post_id'] ?? 0 );
			if ( ! get_post( $post_id ) ) {
				return new WP_Error( 'not_found', 'No post with that ID.' );
			}
			$att = (int) ( $input['attachment_id'] ?? 0 );
			if ( ! $att && ! empty( $input['image_url'] ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';
				$att = media_sideload_image( esc_url_raw( $input['image_url'] ), $post_id, null, 'id' );
				if ( is_wp_error( $att ) ) {
					return $att;
				}
			}
			if ( ! $att ) {
				return new WP_Error( 'no_image', 'Provide attachment_id or image_url.' );
			}
			set_post_thumbnail( $post_id, $att );
			return [
				'post_id'       => $post_id,
				'attachment_id' => $att,
				'thumbnail_url' => get_the_post_thumbnail_url( $post_id, 'full' ),
			];
		},
		'permission_callback' => '__return_true',
	] );
} );

/* ------------------------------------------------------------------ *
 * The MCP server, gated by the key.
 *
 * Registration is made fail-safe: the MCP Adapter's create_server()
 * signature has varied across versions (the observability-handler and
 * transport-permission-callback parameters were added over time). Passing
 * positional args that don't match the *installed* adapter can throw a fatal
 * during rest_api_init and take down the ENTIRE WordPress REST API (every
 * /wp-json route, not just this one). To avoid that we:
 *   1. map our values to the adapter's real parameters by NAME via reflection,
 *      so the call adapts to whatever signature is installed;
 *   2. wrap everything in try/catch( \Throwable ) so a failure degrades to a
 *      disabled MCP server instead of a 500 for all of REST;
 *   3. record the outcome in the `my_mcp_last_init` option, readable via the
 *      key-gated GET /wp-json/my-plugin/diag route below.
 * ------------------------------------------------------------------ */
add_action( 'mcp_adapter_init', function ( $adapter ) {

	$remember = function ( $status ) {
		// Only write when it actually changes, to avoid a DB write per request.
		if ( get_option( 'my_mcp_last_init' ) !== $status ) {
			update_option( 'my_mcp_last_init', $status, false );
		}
	};

	try {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			$remember( 'error: $adapter has no create_server()' );
			return;
		}

		// Resolve classes by name, tolerating namespace/renames across versions.
		$pick = function ( array $candidates ) {
			foreach ( $candidates as $c ) {
				if ( class_exists( $c ) ) {
					return $c;
				}
			}
			return null;
		};

		$transport = $pick( [
			'\\WP\\MCP\\Transport\\HttpTransport',
			'\\WP\\MCP\\Transport\\Http\\RestTransport',
			'\\WP\\MCP\\Transport\\RestTransport',
		] );
		if ( null === $transport ) {
			$remember( 'error: no known HTTP transport class found' );
			return;
		}

		$error_handler = $pick( [
			'\\WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler',
		] ); // null is acceptable; adapter falls back to its Null handler.

		$observability_handler = $pick( [
			'\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler',
		] );

		$permission_cb = function ( $request ) {
			$key = $request->get_header( 'X-API-Key' );
			if ( empty( $key ) ) {
				$key = $request->get_param( 'key' );
			}
			return is_string( $key ) && '' !== $key && hash_equals( my_mcp_expected_key(), $key );
		};

		$tools = [
			'my-plugin/get-site-info',
			'my-plugin/list-posts',
			'my-plugin/get-post',
			'my-plugin/create-post',
			'my-plugin/update-post',
			'my-plugin/publish-post',
			'my-plugin/delete-post',
			'my-plugin/list-terms',
			'my-plugin/upload-media',
			'my-plugin/set-featured-image',
		];

		// Values keyed by every parameter name the adapter has used across versions.
		$by_name = [
			'server_id'                     => 'my-key-server',
			'server_route_namespace'        => 'my-plugin',
			'server_route'                  => 'mcp',
			'server_name'                   => 'My Keyed MCP Server',
			'server_description'            => 'MCP server with API-key auth',
			'server_version'                => '1.0.0',
			'mcp_transports'                => [ $transport ],
			'transports'                    => [ $transport ],
			'error_handler'                 => $error_handler,
			'mcp_error_handler'             => $error_handler,
			'observability_handler'         => $observability_handler,
			'mcp_observability_handler'     => $observability_handler,
			'tools'                         => $tools,
			'resources'                     => [],
			'prompts'                       => [],
			'transport_permission_callback' => $permission_cb,
			'permission_callback'           => $permission_cb,
			'check_permission'              => $permission_cb,
			'mcp_transport_permission_callback' => $permission_cb,
		];

		$ref  = new ReflectionMethod( $adapter, 'create_server' );
		$args = [];
		foreach ( $ref->getParameters() as $p ) {
			$name = $p->getName();
			if ( array_key_exists( $name, $by_name ) ) {
				$args[] = $by_name[ $name ];
			} elseif ( $p->isOptional() ) {
				$args[] = $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null;
			} elseif ( $p->allowsNull() ) {
				$args[] = null;
			} else {
				$remember( 'error: unmapped required create_server() param "' . $name . '"' );
				return;
			}
		}

		$result = $ref->invokeArgs( $adapter, $args );

		if ( is_wp_error( $result ) ) {
			$remember( 'wp_error: ' . $result->get_error_code() . ' — ' . $result->get_error_message() );
			return;
		}
		$remember( 'ok' );
	} catch ( \Throwable $e ) {
		$msg = 'throwable: ' . get_class( $e ) . ': ' . $e->getMessage()
			. ' @ ' . $e->getFile() . ':' . $e->getLine();
		$remember( $msg );
		error_log( 'my-mcp create_server failed — ' . $msg );
		// Swallowed on purpose: a broken MCP server must not 500 the whole REST API.
	}
}, 10 );

/* ------------------------------------------------------------------ *
 * Diagnostic route: GET /wp-json/my-plugin/diag  (key required)
 * Reports PHP version, which adapter classes/signature are installed, and the
 * last server-registration outcome — so failures can be read without server
 * log access. Safe: read-only, key-gated, no secrets returned.
 * ------------------------------------------------------------------ */
add_action( 'rest_api_init', function () {
	register_rest_route( 'my-plugin', '/diag', [
		'methods'             => 'GET',
		'callback'            => 'my_mcp_diag',
		'permission_callback' => function ( $request ) {
			$key = $request->get_header( 'X-API-Key' );
			if ( empty( $key ) ) {
				$key = $request->get_param( 'key' );
			}
			return is_string( $key ) && '' !== $key && hash_equals( my_mcp_expected_key(), $key );
		},
	] );
}, 5 );

function my_mcp_diag() {
	$adapter_class = null;
	foreach ( [ '\\WP\\MCP\\Core\\McpAdapter', '\\WP\\MCP\\McpAdapter' ] as $c ) {
		if ( class_exists( $c ) ) {
			$adapter_class = $c;
			break;
		}
	}

	$create_server_params = null;
	if ( $adapter_class && method_exists( $adapter_class, 'create_server' ) ) {
		$create_server_params = [];
		foreach ( ( new ReflectionMethod( $adapter_class, 'create_server' ) )->getParameters() as $p ) {
			$create_server_params[] = $p->getName() . ( $p->isOptional() ? '?' : '' );
		}
	}

	return [
		'php_version'          => PHP_VERSION,
		'adapter_class'        => $adapter_class,
		'create_server_params' => $create_server_params,
		'classes'              => [
			'HttpTransport'               => class_exists( '\\WP\\MCP\\Transport\\HttpTransport' ),
			'RestTransport'               => class_exists( '\\WP\\MCP\\Transport\\Http\\RestTransport' ),
			'ErrorLogMcpErrorHandler'     => class_exists( '\\WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler' ),
			'NullMcpObservabilityHandler' => class_exists( '\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler' ),
		],
		'abilities_api'        => function_exists( 'wp_register_ability' ),
		'last_init'            => get_option( 'my_mcp_last_init', '(never ran)' ),
	];
}
