<?php
/**
 * Manage syncing of content between WP and Elasticsearch for posts
 *
 * @since  1.0
 * @package elasticpress
 */

namespace ElasticPress\Indexable\Post;

use ElasticPress\Indexables as Indexables;
use ElasticPress\Elasticsearch as Elasticsearch;
use ElasticPress\SyncManager as SyncManagerAbstract;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Sync manager class
 */
class SyncManager extends SyncManagerAbstract {

	/**
	 * Setup actions and filters
	 *
	 * @since 0.1.2
	 */
	public function setup() {
		if ( defined( 'WP_IMPORTING' ) && true === WP_IMPORTING ) {
			return;
		}

		if ( ! Elasticsearch::factory()->get_elasticsearch_version() ) {
			return;
		}

		add_action( 'wp_insert_post', array( $this, 'action_sync_on_update' ), 999, 3 );
		add_action( 'add_attachment', array( $this, 'action_sync_on_update' ), 999, 3 );
		add_action( 'edit_attachment', array( $this, 'action_sync_on_update' ), 999, 3 );
		add_action( 'delete_post', array( $this, 'action_delete_post' ) );
		add_action( 'delete_blog', array( $this, 'action_delete_blog_from_index' ) );
		add_action( 'make_delete_blog', array( $this, 'action_delete_blog_from_index' ) );
		add_action( 'make_spam_blog', array( $this, 'action_delete_blog_from_index' ) );
		add_action( 'archive_blog', array( $this, 'action_delete_blog_from_index' ) );
		add_action( 'deactivate_blog', array( $this, 'action_delete_blog_from_index' ) );
		add_action( 'updated_post_meta', array( $this, 'action_queue_meta_sync' ), 10, 4 );
		add_action( 'added_post_meta', array( $this, 'action_queue_meta_sync' ), 10, 4 );
		add_action( 'deleted_post_meta', array( $this, 'action_queue_meta_sync' ), 10, 4 );
	}

	/**
	 * Check to see if current site is indexable (public).
	 *
	 * @since  3.0
	 * @return bool
	 */
	protected function is_site_indexable() {
		if ( ! is_multisite() ) {
			return true;
		}

		$blog_id = get_current_blog_id();

		$args = array(
			'fields'            => 'ids',
			'public'            => 1,
			'archived'          => 0,
			'spam'              => 0,
			'deleted'           => 0,
			'update_site_cache' => false,
		);

		if ( function_exists( 'get_sites' ) ) {
			$sites = get_sites( $args );
		} else {
			$sites = wp_list_pluck( wp_get_sites( $args ), 'blog_id' );
		}

		return in_array( $blog_id, apply_filters( 'ep_indexable_blogs', $sites ), true );
	}

	/**
	 * When whitelisted meta is updated, queue the post for reindex
	 *
	 * @param  int|array $meta_id Meta id.
	 * @param  int       $object_id Object id.
	 * @param  string    $meta_key Meta key.
	 * @param  string    $meta_value Meta value.
	 * @since  2.0
	 */
	public function action_queue_meta_sync( $meta_id, $object_id, $meta_key, $meta_value ) {
		$indexable = Indexables::factory()->get( 'post' );

		$indexable_post_statuses = $indexable->get_indexable_post_status();
		$post_type               = get_post_type( $object_id );

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || 'revision' === $post_type ) {
			// Bypass saving if doing autosave or post type is revision.
			return;
		}

		$post = get_post( $object_id );

		// If the post is an auto-draft - let's abort.
		if ( 'auto-draft' === $post->post_status ) {
			return;
		}

		if ( in_array( $post->post_status, $indexable_post_statuses, true ) ) {
			$indexable_post_types = $indexable->get_indexable_post_types();

			if ( in_array( $post_type, $indexable_post_types, true ) ) {
				if ( apply_filters( 'ep_post_sync_kill', false, $object_id ) || ! $this->is_site_indexable() ) {
					return;
				}

				$this->sync_queue[ $object_id ] = true;
			}
		}
	}

	/**
	 * Remove blog from index when a site is deleted, archived, or deactivated
	 *
	 * @param int $blog_id WP Blog ID.
	 */
	public function action_delete_blog_from_index( $blog_id ) {
		$indexable = Indexables::factory()->get( 'post' );

		if ( $indexable->index_exists( $blog_id ) && ! apply_filters( 'ep_keep_index', false ) ) {
			$indexable->delete_index( $blog_id );
		}
	}

	/**
	 * Delete ES post when WP post is deleted
	 *
	 * @param int $post_id Post id.
	 * @since 0.1.0
	 */
	public function action_delete_post( $post_id ) {
		if ( ( ! current_user_can( 'edit_post', $post_id ) && ! apply_filters( 'ep_sync_delete_permissions_bypass', false, $post_id ) ) || 'revision' === get_post_type( $post_id ) ) {
			return;
		}

		do_action( 'ep_delete_post', $post_id );

		Indexables::factory()->get( 'post' )->delete( $post_id, false );
	}

	/**
	 * Sync ES index with what happened to the post being saved
	 *
	 * @param int $post_id Post id.
	 * @since 0.1.0
	 */
	public function action_sync_on_update( $post_id ) {
		$indexable = Indexables::factory()->get( 'post' );
		$post_type = get_post_type( $post_id );

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || 'revision' === $post_type ) {
			// Bypass saving if doing autosave or post type is revision.
			return;
		}

		if ( ! apply_filters( 'ep_sync_insert_permissions_bypass', false, $post_id ) ) {
			if ( ! current_user_can( 'edit_post', $post_id ) && ( ! defined( 'DOING_CRON' ) || ! DOING_CRON ) ) {
				// Bypass saving if user does not have access to edit post and we're not in a cron process.
				return;
			}
		}

		$post = get_post( $post_id );

		// If the post is an auto-draft - let's abort.
		if ( 'auto-draft' === $post->post_status ) {
			return;
		}

		$indexable_post_statuses = $indexable->get_indexable_post_status();

		// Our post was published, but is no longer, so let's remove it from the Elasticsearch index.
		if ( ! in_array( $post->post_status, $indexable_post_statuses, true ) ) {
			$this->action_delete_post( $post_id );
		} else {
			$indexable_post_types = $indexable->get_indexable_post_types();

			if ( in_array( $post_type, $indexable_post_types, true ) ) {
				do_action( 'ep_sync_on_transition', $post_id );


				if ( apply_filters( 'ep_post_sync_kill', false, $post_id ) || ! $this->is_site_indexable() ) {
					return;
				}

				$this->sync_queue[ $post_id ] = true;
			}
		}
	}
}
