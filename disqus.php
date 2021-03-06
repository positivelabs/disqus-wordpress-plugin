<?php
/*
Plugin Name: DISQUS Comment System
Plugin URI: http://disqus.com/
Description: The DISQUS comment system replaces your WordPress comment system with your comments hosted and powered by DISQUS. Head over to the Comments admin page to set up your DISQUS Comment System.
Author: DISQUS.com <team@disqus.com>
Version: 2.02-2969
Author URI: http://disqus.com/

*/

define('DISQUS_URL',			'http://disqus.com');
define('DISQUS_API_URL',		DISQUS_URL);
define('DISQUS_DOMAIN',			'disqus.com');
define('DISQUS_IMPORTER_URL',	'http://import.disqus.net');
define('DISQUS_MEDIA_URL',		'http://media.disqus.com');
define('DISQUS_RSS_PATH',		'/latest.rss');

require_once('lib/api.php');

/**
 * DISQUS WordPress plugin version.
 *
 * @global	string	$dsq_version
 * @since	1.0
 */
$dsq_version = '2.02';
/**
 * Response from DISQUS get_thread API call for comments template.
 *
 * @global	string	$dsq_response
 * @since	1.0
 */
$dsq_response = '';
/**
 * Comment sort option.
 *
 * @global	string	$dsq_sort
 * @since	1.0
 */
$dsq_sort = 1;
/**
 * Flag to determine whether or not the comment count script has been embedded.
 *
 * @global	string	$dsq_cc_script_embedded
 * @since	1.0
 */
$dsq_cc_script_embedded = false;
/**
 * DISQUS API instance.
 *
 * @global	string	$dsq_api
 * @since	1.0
 */
$dsq_api = new DisqusAPI(get_option('disqus_forum_url'), get_option('disqus_api_key'));

/**
 * Template tags
 */
// TODO: Add widget template tags.


/**
 * Helper functions.
 */

function dsq_legacy_mode() {
	return get_option('disqus_forum_url') && !get_option('disqus_api_key');
}

function dsq_is_installed() {
	return get_option('disqus_forum_url') && get_option('disqus_api_key');
}

function dsq_can_replace() {
	global $id, $post;
	$replace = get_option('disqus_replace');

	if ( 'draft' == $post->post_status )   { return false; }
	if ( !get_option('disqus_forum_url') ) { return false; }
	else if ( 'all' == $replace )          { return true; }

	if ( !isset($post->comment_count) ) {
		$num_comments = 0;
	} else {
		if ( 'empty' == $replace ) {
			// Only get count of comments, not including pings.

			// If there are comments, make sure there are comments (that are not track/pingbacks)
			if ( $post->comment_count > 0 ) {
				// Yuck, this causes a DB query for each post.  This can be
				// replaced with a lighter query, but this is still not optimal.
				$comments = get_approved_comments($post->ID);
				foreach ( $comments as $comment ) {
					if ( $comment->comment_type != 'trackback' && $comment->comment_type != 'pingback' ) {
						$num_comments++;
					}
				}
			} else {
				$num_comments = 0;
			}
		}
		else {
			$num_comments = $post->comment_count;
		}
	}

	return ( ('empty' == $replace && 0 == $num_comments)
		|| ('closed' == $replace && 'closed' == $post->comment_status) );
}

function dsq_manage_dialog($message, $error = false) {
	global $wp_version;

	echo '<div '
		. ( $error ? 'id="disqus_warning" ' : '')
		. 'class="updated fade'
		. ( ($wp_version < 2.5 && $error) ? '-ff0000' : '' )
		. '"><p><strong>'
		. $message
		. '</strong></p></div>';
}

function dsq_sync_comments($post, $comments) {
	global $wpdb;

	// Get last_comment_date id for $post with Disqus metadata
	// (This is the date that is stored in the Disqus DB.)
	$last_comment_date = $wpdb->get_var('SELECT max(comment_date) FROM ' . $wpdb->prefix . 'comments WHERE comment_post_ID=' . intval($post->ID) . " AND comment_agent LIKE 'Disqus/%';");
	if ( $last_comment_date ) {
		$last_comment_date = strtotime($last_comment_date);
	}

	if ( !$last_comment_date ) {
		$last_comment_date = 0;
	}

	foreach ( $comments as $comment ) {
		if ( $comment['imported'] ) {
			continue;
		} else if ( $comment['date'] <= $last_comment_date ) {
			// If comment date of comment is <= last_comment_date, skip comment.
			continue;
		} else {
			// Else, insert_comment
			$commentdata = array(
				'comment_post_ID' => $post->ID,
				'comment_author' => $comment['user']['display_name'],
				'comment_author_email' => $comment['user']['email'],
				'comment_author_url' => $comment['user']['url'],
				'comment_author_IP' => $comment['user']['ip_address'],
				'comment_date' => date('Y-m-d H:i:s', $comment['date']),
				'comment_date_gmt' => date('Y-m-d H:i:s', $comment['date_gmt']),
				'comment_content' => $comment['message'],
				'comment_approved' => 1,
				'comment_agent' => 'Disqus/1.0:' . intval($comment['id']),
				'comment_type' => '',
			);
			wp_insert_comment($commentdata);
		}
	}
}

/**
 *  Filters/Actions
 */

function dsq_comments_template($value) {
	global $dsq_response;
	global $dsq_sort;
	global $dsq_api;
	global $post;

	if ( ! (is_single() || is_page() || $withcomments) ) {
		return;
	}

	if ( !dsq_can_replace() ) {
		return $value;
	}

	if ( dsq_legacy_mode() ) {
		return dirname(__FILE__) . '/comments-legacy.php';
	}

	$permalink = get_permalink();
	$title = get_the_title();
	$excerpt = get_the_excerpt();

	$dsq_sort = get_option('disqus_sort');
	if ( is_numeric($_COOKIE['disqus_sort']) ) {
		$dsq_sort = $_COOKIE['disqus_sort'];
	}

	if ( is_numeric($_GET['dsq_sort']) ) {
		setcookie('disqus_sort', $_GET['dsq_sort']);
		$dsq_sort = $_GET['dsq_sort'];
	}

	// Call "get_thread" API method.
	$dsq_response = $dsq_api->get_thread($post, $permalink, $title, $excerpt);
	if( $dsq_response < 0 ) {
		return false;
	}
	// Sync comments with database.
	dsq_sync_comments($post, $dsq_response['posts']);

	// TODO: If a disqus-comments.php is found in the current template's
	// path, use that instead of the default bundled comments.php
	//return TEMPLATEPATH . '/disqus-comments.php';

	return dirname(__FILE__) . '/comments.php';
}

function dsq_comment_count() {
	global $dsq_cc_script_embedded;

	if ( $dsq_cc_script_embedded ) {
		return;
	} else if ( (is_single() || is_page() || $withcomments || is_feed()) ) {
		return;
	}

	?>
	
	<script type="text/javascript">
	// <![CDATA[
		(function() {
			var links = document.getElementsByTagName('a');
			var query = '?';
			for(var i = 0; i < links.length; i++) {
				if(links[i].href.indexOf('#disqus_thread') >= 0) {
					links[i].innerHTML = 'View Comments';
					query += 'url' + i + '=' + encodeURIComponent(links[i].href) + '&';
				}
			}
			document.write('<script type="text/javascript" src="<?php echo DISQUS_URL ?>/forums/<?php echo strtolower(get_option('disqus_forum_url')); ?>/get_num_replies.js' + query + '"><' + '/script>');
		})();
	//]]>
	</script>

	<?php

	$dsq_cc_script_embedded = true;
}

function dsq_get_comments_number($num_comments) {
	$replace = get_option('disqus_replace');

	// HACK: Don't allow $num_comments to be 0.  If we're only replacing
	// closed comments, we don't care about the value. For
	// comments_popup_link();
	if ( $replace != 'closed' && 0 == $num_comments ) {
		return -1;
	} else {
		return $num_comments;
	}
}

// Mark entries in index to replace comments link.
function dsq_comments_number($comment_text) {
	if ( dsq_can_replace() ) {
		ob_start();
		the_permalink();
		$the_permalink = ob_get_contents();
		ob_end_clean();

		return '</a><noscript><a href="http://' . strtolower(get_option('disqus_forum_url')) . '.' . DISQUS_DOMAIN . '/?url=' . $the_permalink .'">View comments</a></noscript><a href="' . $the_permalink . '#disqus_thread">Comments</a>';
	} else {
		return $comment_text;
	}
}

function dsq_bloginfo_url($url) {
	if ( get_feed_link('comments_rss2') == $url ) {
		return 'http://' . strtolower(get_option('disqus_forum_url')) . '.' . DISQUS_DOMAIN . DISQUS_RSS_PATH;
	} else {
		return $url;
	}
}

// For WordPress 2.0.x
function dsq_loop_start() {
	global $comment_count_cache;

	if ( isset($comment_count_cache) ) {
		foreach ( $comment_count_cache as $key => $value ) {
			if ( 0 == $value ) {
				$comment_count_cache[$key] = -1;
			}
		}
	}
}

function dsq_add_pages() {
	global $menu, $submenu;

	add_submenu_page('edit-comments.php', 'DISQUS', 'DISQUS', 8, 'disqus', dsq_manage);

	// TODO: This does not work in WP2.0.

	// Replace Comments top-level menu link with link to our page
	foreach ( $menu as $key => $value ) {
		if ( 'edit-comments.php' == $menu[$key][2] ) {
			$menu[$key][2] = 'edit-comments.php?page=disqus';
		}
	}

	add_options_page('DISQUS', 'DISQUS', 8, 'disqus', dsq_manage);
}

function dsq_manage() {
	require_once('admin-header.php');
	include_once('manage.php');
}

// Always add Disqus management page to the admin menu
add_action('admin_menu', 'dsq_add_pages');

function dsq_warning() {
	global $wp_version;

	if ( !get_option('disqus_forum_url') && !isset($_POST['forum_url']) && $_GET['page'] != 'disqus' ) {
		dsq_manage_dialog('You must <a href="edit-comments.php?page=disqus">configure the plugin</a> to enable the DISQUS comment system.', true);
	}

	if ( dsq_legacy_mode() && $_GET['page'] == 'disqus' ) {
		dsq_manage_dialog('DISQUS is running in legacy mode.  (<a href="edit-comments.php?page=disqus">Click here to configure</a>)');
	}
}

function dsq_check_version() {
	global $dsq_api;

	$latest_version = $dsq_api->wp_check_version();
	if ( $latest_version ) {
		dsq_manage_dialog('You are running an old version of the DISQUS plugin.  Please <a href="http://blog.disqus.net">check the blog</a> for updates.');
	}
}

add_action('admin_notices', 'dsq_warning');
add_action('admin_notices', 'dsq_check_version');

// Only replace comments if the disqus_forum_url option is set.
add_filter('comments_template', 'dsq_comments_template');
add_filter('comments_number', 'dsq_comments_number');
add_filter('get_comments_number', 'dsq_get_comments_number');
add_filter('bloginfo_url', 'dsq_bloginfo_url');
add_action('loop_start', 'dsq_loop_start');

// For comment count script.
if ( !get_option('disqus_cc_fix') ) {
	add_action('loop_end', 'dsq_comment_count');
}
add_action('wp_footer', 'dsq_comment_count');

?>
