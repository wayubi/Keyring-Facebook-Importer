<?php

// This is a horrible hack, because WordPress doesn't support dependencies/load-order.
// We wrap our entire class definition in a function, and then only call that on a hook
// where we know that the class we're extending is available. *hangs head in shame*
function Keyring_Facebook_Importer() {

class Keyring_Facebook_Importer extends Keyring_Importer_Base {
	const SLUG              = 'facebook';    // e.g. 'twitter' (should match a service in Keyring)
	const LABEL             = 'Facebook';    // e.g. 'Twitter'
	const KEYRING_SERVICE   = 'Keyring_Service_Facebook';    // Full class name of the Keyring_Service this importer requires
	const REQUESTS_PER_LOAD = 3;     // How many remote requests should be made before reloading the page?

	var $api_endpoints = array(
		// '/albums',
		// '/photos',
		'/posts'
	);

	var $api_endpoint_fields = array(
		'/albums' => 'id,name,created_time,updated_time,privacy',
		'/photos' => 'id,name,created_time,updated_time,source',
		// '/posts'  => 'id,created_time,updated_time,name,message,description,story,link,source,picture,full_picture,attachments,type&until=2020-08-04T21:44:49&since=2020-08-04'
		'/posts'  => 'id,object_id,created_time,updated_time,name,message,description,story,link,source,picture,full_picture,attachments,permalink_url,type,comments&until=2021-12-31&limit=1'
	);

	// '/posts'  => 'id,created_time,updated_time,name,message,description,story,link,source,picture,full_picture,attachments,type&until=2020-06-01'

	var $current_endpoint = null;
	var $endpoint_prefix = null;

	function __construct() {
		$rv = parent::__construct();

		if ( $this->get_option( 'facebook_page', '' ) ) {
			$this->endpoint_prefix = $this->get_option( 'facebook_page' );
		} else {
			$this->endpoint_prefix = "me";
		}

		$this->current_endpoint = $this->endpoint_prefix . $this->api_endpoints[ min( count( $this->api_endpoints ) - 1, $this->get_option( 'endpoint_index', 0 ) ) ];
		add_action( 'keyring_importer_facebook_custom_options', array( $this, 'custom_options' ) );

		return $rv;
	}

	function custom_options() {
		?>
		<tr valign="top">
			<th scope="row">
				<label for="include_rts"><?php _e( 'Post Status', 'keyring-facebook' ); ?></label>
			</th>
			<td>
				<?php

				$prev_post_status = $this->get_option( 'fb_post_status' );

				?>
				<select name="fb_post_status" id="fb_post_status">
					<option value="publish" <?php selected( $prev_post_status == 'publish' ); ?>><?php esc_html_e( 'Publish', 'keyring-facebook' ); ?></option>
					<option value="private" <?php selected( $prev_post_status == 'private' ); ?>><?php esc_html_e( 'Private', 'keyring-facebook' ); ?></option>
				</select>
			</td>
		</tr>
		<tr valign="top">
			<th scope="row">
				<label for="include_rts"><?php esc_html_e( 'Import From', 'keyring-facebook' ); ?></label>
			</th>
			<td>
				<?php

				$prev_fb_page = $this->get_option( 'facebook_page' );
				$fb_pages = $this->retrieve_pages();

				?>
				<select name="facebook_page" id="facebook_page">
					<option value="0"><?php esc_html_e( 'Personal Profile', 'keyring-facebook' ); ?></option>
					<?php

					if (!empty($fb_pages) && is_array($fb_pages)) {
						foreach ( $fb_pages as $fb_page ) {
							printf( '<option value="%1$s"' . selected( $prev_fb_page == $fb_page['id'] ) . '>%2$s</option>', esc_attr( $fb_page['id'] ), esc_html( $fb_page['name'] ) );
						}
					}

					?>
				</select>
			</td>
		</tr>
		<tr valign="top">
			<th scope="row">
				<label for="include_rts"><?php esc_html_e( 'Comment Trigger', 'keyring-facebook' ); ?></label>
			</th>
			<td>
				<?php
					$prev_comment_trigger = $this->get_option( 'comment_trigger' );
				?>
				<input type="text" class="regular-text" name="comment_trigger" id="comment_trigger" value="<?php echo esc_html( $prev_comment_trigger ); ?>" />
				<p class="description"><?php _e( 'Initial text at the beginning of the comment that triggers that comment to be imported.', 'keyring' ); ?></p>
			</td>
		</tr>
		<?php
	}

	function handle_request_options() {
		// Validate options and store them so they can be used in auto-imports
		if ( empty( $_POST['category'] ) || !ctype_digit( $_POST['category'] ) )
			$this->error( __( "Make sure you select a valid category to import your statuses into." ) );

		if ( empty( $_POST['author'] ) || !ctype_digit( $_POST['author'] ) )
			$this->error( __( "You must select an author to assign to all statuses." ) );

		if ( isset( $_POST['auto_import'] ) )
			$_POST['auto_import'] = true;
		else
			$_POST['auto_import'] = false;

		// If there were errors, output them, otherwise store options and start importing
		if ( count( $this->errors ) ) {
			$this->step = 'options';
		} else {
			$this->set_option( array(
				'category'        => (int) $_POST['category'],
				'tags'            => explode( ',', $_POST['tags'] ),
				'author'          => (int) $_POST['author'],
				'auto_import'     => $_POST['auto_import'],
				'facebook_page'   => $_POST['facebook_page'],
				'fb_post_status'  => $_POST['fb_post_status'],
				'comment_trigger' => $_POST['comment_trigger']
			) );

			$this->step = 'import';
		}
	}

	function build_request_url() {

		$endpoint_prefix_length = strlen($this->endpoint_prefix);
		$endpoint = substr($this->current_endpoint, $endpoint_prefix_length);

		// Base request URL
		$url = "https://graph.facebook.com/" . $this->current_endpoint . "?fields=" . $this->api_endpoint_fields[$endpoint];

		return $url;

		if ( $this->auto_import ) {
			// Get most recent checkin we've imported (if any), and its date so that we can get new ones since then
			$latest = get_posts( array(
				'numberposts' => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'meta_key'    => 'endpoint',
				'meta_value'  => $this->current_endpoint,
				'tax_query'   => array( array(
					'taxonomy' => 'keyring_services',
					'field'    => 'slug',
					'terms'    => array( $this->taxonomy->slug ),
					'operator' => 'IN',
				) ),
			) );

			// If we have already imported some, then start since the most recent
			if ( $latest ) {
				$url = add_query_arg( 'since', strtotime( $latest[0]->post_date_gmt ) + 1, $url );
			}
		} else {
			// Handle page offsets (only for non-auto-import requests)
			$url = $this->get_option( 'paging:' . $this->current_endpoint, $url );
		}

		return $url;
	}

	function extract_posts_from_data( $raw ) {
		global $wpdb;

		$importdata = $raw;

		if ( null === $importdata ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-facebook-importer-failed-download', __( 'Failed to download your statuses from Facebook. Please wait a few minutes and try again.' ) );
		}

		// Make sure we have some statuses to parse
		if ( !is_object( $importdata ) || !count( $importdata->data ) ) {
			if ( $this->get_option( 'endpoint_index' ) == ( count( $this->api_endpoints ) - 1 ) )
				$this->finished = true;

			$this->set_option( 'paging:' . $this->current_endpoint, null );
			$this->rotate_endpoint();
			return;
		}

		switch ( $this->current_endpoint ) {
			case $this->endpoint_prefix . '/posts':
				$this->extract_posts_from_data_posts( $importdata );
			break;
			case $this->endpoint_prefix . '/albums':
				$this->extract_posts_from_data_albums( $importdata );
			break;
			case $this->endpoint_prefix . '/photos':
				$this->extract_posts_from_data_photos( $importdata );
			break;
		}

		if ( isset( $importdata->paging ) && isset( $importdata->paging->next ) ) {
			$this->set_option( 'paging:' . $this->current_endpoint, $importdata->paging->next );
		}
		else {
			if ( $this->get_option( 'endpoint_index' ) == ( count( $this->api_endpoints ) - 1 ) )
				$this->finished = true;

			$this->set_option( 'paging:' . $this->current_endpoint, null );
			$this->rotate_endpoint();
		}
	}

	private function extract_posts_from_data_posts( $importdata ) {
		global $wpdb;

		foreach ( $importdata->data as $post ) {

			$facebook_id = substr($post->id, strpos($post->id, '_') + 1);

			$post_id = $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = 'facebook_id' AND meta_value = %s", $facebook_id ) );

			// Other bits
			$post_author = $this->get_option( 'author' );

			$post_status = $this->get_option( 'fb_post_status' );

			if ($post_id)
				continue;

			$facebook_raw = $post;

			// Parse/adjust dates
			$post_date_gmt = gmdate( 'Y-m-d H:i:s', strtotime( $post->created_time ) );
			$post_date = get_date_from_gmt( $post_date_gmt );

			// Prepare media

			$videos = array();
			$photos = array();

			if ($post->type == 'photo' || $post->type == 'video') {

				if (!empty($post->attachments)) {
					foreach ($post->attachments->data as $data) {
						if (!empty($data->subattachments)) {
							foreach ($data->subattachments->data as $index => $s_data) {
								if ($s_data->type == 'photo') {
									$photo_object = $this->service->request('https://graph.facebook.com/' . $s_data->target->id . '?fields=images');
									$images = array();
									foreach ($photo_object->images as $image) {
										$images[$image->height] = $image->source;
									}
									krsort($images);
									$image = array_shift($images);
									$photos[] = $image;
								} else if ($s_data->type == 'video') {
									$video_object = $this->service->request('https://graph.facebook.com/' . $s_data->target->id . '?fields=source,thumbnails');
									$videos[] = $video_object->source;
									if ($index == 0) {
										if (!empty($video_object->thumbnails)) {
											foreach ($video_object->thumbnails->data as $s_data) {
												$photos[] = $s_data->uri;
												break;
											}
										} else {
											$photos[] = $data->media->image->src;
										}
									}
								} else {
									$photos[] = $s_data->media->image->src;
								}
							}
						} else {
							if ($data->type == 'photo') {
								$photo_object = $this->service->request('https://graph.facebook.com/' . $data->target->id . '?fields=images');
								$images = array();
								foreach ($photo_object->images as $image) {
									$images[$image->height] = $image->source;
								}
								krsort($images);
								$image = array_shift($images);
								$photos[] = $image;
							} else if ($data->type == 'video_inline' && !empty($data->media->source)) {
								$video_object = $this->service->request('https://graph.facebook.com/' . $data->target->id . '?fields=source,thumbnails');
								$videos[] = $video_object->source;
								if (!empty($video_object->thumbnails)) {
									foreach ($video_object->thumbnails->data as $t_data) {
										$photos[] = $t_data->uri;
										break;
									}
								} else {
									$photos[] = $data->media->image->src;
								}
							} else {
								$photos[] = $data->media->image->src;
							}
						}
					}
				} else {
					if ($post->type == 'photo') {
						$photos[] = $post->full_picture;
					} else if ($post->type == 'video') {
						$videos[] = $post->source;
						$photos[] = $post->full_picture;
					}
				}

			} else if (!empty($post->full_picture)) {
				$photos[] = $post->full_picture;
			}

			// Prepare post title

			$post_title = '';

			if (!empty($post->message))
				$post_title = $post->message;
			else if (!empty($post->story))
				$post_title = $post->story;
			else if (!empty($post->name))
				$post_title = $post->name;
			else
				$post_title = 'Untitled';

			$message = preg_split('/\n/', $post_title);
			$title_words = explode(' ', strip_tags($message[0]));
			$post_title  = implode(' ', array_slice($title_words, 0, 9));

			$post_title = rtrim($post_title, ',');

			if (count($title_words) > 9) {
				if (!in_array(substr($post_title, -1), array('.', '?', '!', ',', ';', ':')))
					$post_title .= '...';
			}

			// Prepare post body

			$post_content = '';

			// Inject first image
			if (!empty($photos)) {
				$post_content .= '<p><img src="' . $photos[0] . '" /></p><br>';
			}

			// Continue with text

			if (!empty($post->story))
				$post_content .= '<p>' . make_clickable(addslashes($post->story)) . '</p><br>';

			if (!empty($post->message)) {
				$message = $post->message;
				$message = preg_replace('/(https{0,1}:\/\/www.facebook.com\/).+?\/posts\/(\d+)/', '$1$2', $message);
				$post_content .= '<p>' . make_clickable(addslashes($message)) . '</p><br>';
			}

			$post_content .= '<p><!--fb_post-break--></p><br>';

			// Inject remaining images
			foreach ($photos as $index => $photo) {
				if ($index == 0)
					continue;
				$post_content .= '<p><img src="' . $photo . '" /></p><br>';
			}

			// Inject videos
			if ($post->type == 'video') {
				if (!empty($videos)) {
					foreach ($videos as $video) {
						$post_content .= '<p>' . $video . '</p><br>';
					}
				} else {
					$link = $post->link;
					$post_content .= '<p>' . $link . '</p><br>';
				}
			}

			// Prepare comments

			$comment_trigger = $this->get_option( 'comment_trigger' );

			if (!empty($comment_trigger)) {
				if (!empty($post->comments)) {
					foreach ($post->comments->data as $data) {
						
						if (substr($data->message, 0, strlen($comment_trigger)) != $comment_trigger)
							continue;

						$message = ltrim(substr($data->message, strlen($comment_trigger)));

						if (!empty($message)) {
							$message = preg_replace('/\n\n/', '</p><p>', $message);
							if (!stristr($message, 'youtube.com') && !stristr($message, 'twitter.com')) {
								$message = make_clickable($message);
							}
							$post_content .= '<p>' . $message . '</p><br>';
						}

						$comment_object = $this->service->request('https://graph.facebook.com/' . $data->id . '?fields=attachment');

						if (empty($comment_object->attachment))
							continue;

						$attachment = $comment_object->attachment;

						if ($attachment->type == 'photo') {
							$photo_object = $this->service->request('https://graph.facebook.com/' . $attachment->target->id . '?fields=images');
							$images = array();
							foreach ($photo_object->images as $image) {
								$images[$image->height] = $image->source;
							}
							krsort($images);
							$image = array_shift($images);
							$photos[] = $image;
							$post_content .= '<p><img src="' . $image . '" /></p><br>';
						} else if ($attachment->type == 'video_inline') {
							$video_object = $this->service->request('https://graph.facebook.com/' . $attachment->target->id . '?fields=source');
							$videos[] = $video_object->source;
							$post_content .= '<p>' . $video_object->source . '</p><br>';
						}

					}
				}
			}

			$post_content .= '<p><a href="https://www.facebook.com/' . $facebook_id . '">Facebook</a>' . '</p><br>';

			// Prepare link

			if (!empty($post->name) || !empty($post->description)) {
				$post_content .= '<blockquote>';

				if (!empty($post->name))
					$post_content .= '<p>' . $post->name . '</p><br>';

				if (!empty($post->description))
					$post_content .= '<p>' . make_clickable(addslashes($post->description)) . '</p><br>';

				if (!empty($post->link)) {
					if (stristr($post->link, 'facebook.com')) {
						if ($post->link != $post->permalink_url) {
							$post_content .= '<p><a href="' . $post->link . '">Facebook</a></p><br>';
						}
					} else if (stristr($post->link, 'youtube.com')) {
						$post_content .= '<p><a href="' . $post->link . '">YouTube</a></p><br>';
					} else {
						$post_content .= '<p>' . make_clickable($post->link) . '</p><br>';
					}
				}

				$post_content .= '</blockquote>';
			}

			// Prepare tags

			$tags = $this->get_option( 'tags' );

			switch ($post->type) {
				case 'photo':
					$tags[] = 'images';
					break;
				case 'video':
					$tags[] = 'videos';
					break;
				case 'link':
					$tags[] = 'links';
					break;
				default:
					break;
			}

			// Apply selected category
			$post_category = array( $this->get_option( 'category' ) );

			// Other bits
			$post_author = $this->get_option( 'author' );
			$post_status = $this->get_option( 'fb_post_status' );

			if ( ! $post_status ) {
				if ( isset( $post->privacy ) && isset( $post->privacy->value ) && ! empty( $post->privacy->value ) ) {
					$post_status = 'private';
				}
				else {
					$post_status = 'publish';
				}
			}

			$compact = compact(
				'post_author',
				'post_date',
				'post_date_gmt',
				'post_content',
				'post_title',
				'post_status',
				'post_category',
				'facebook_id',
				'tags',
				'facebook_raw',
				'photos',
				'videos'
			);

			$this->posts[] = $compact;
		}
	}

	private function extract_posts_from_data_albums( $importdata ) {
		global $wpdb;

		foreach ( $importdata->data as $album ) {
			$facebook_id = $album->id;

			$post_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'facebook_id' AND meta_value = %s", $facebook_id ) );

			if ( $post_id ) {
				$original_post = get_post( $post_id );

				// Pull in any photos added since we last updated the album.
				if ( strtotime( $original_post->post_modified_gmt ) < strtotime( $album->updated_time ) ) {
					$new_photos = $this->retrieve_album_photos( $album->id, strtotime( $original_post->post_modified_gmt ) );

					foreach ( $new_photos as $photo ) {
						$this->sideload_photo_to_album( $photo, $post_id );
					}

					$original_post->post_modified_gmt = gmdate( 'Y-m-d H:i:s', strtotime( $album->updated_time ) );
					$original_post->post_modified = get_date_from_gmt( $post->post_modified_gmt );
					wp_update_post( (array) $original_post );
				}
			}
			else {
				// Create a post for this gallery.
				$post = array();
				$post['post_title'] = $album->name;
				$post['post_content'] = '[gallery type="rectangular"]';
				$post['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', strtotime( $album->created_time ) );
				$post['post_date'] = get_date_from_gmt( $post['post_date_gmt'] );
				$post['post_modified_gmt'] = gmdate( 'Y-m-d H:i:s', strtotime( $album->updated_time ) );
				$post['post_modified'] = get_date_from_gmt( $post['post_modified_gmt'] );
				$post['post_type'] = 'post';
				$post['post_author'] = $this->get_option( 'author' );
				$post['tags'] = $this->get_option( 'tags' );
				$post['post_category'] = array( $this->get_option( 'category' ) );
				$post['post_status'] = $this->get_option( 'fb_post_status' );

				if ( ! $post['post_status'] ) {
					if ( isset( $album->privacy ) && isset( $album->privacy->value ) && ! empty( $album->privacy->value ) ) {
						$post['post_status'] = 'private';
					}
					else {
						$post['post_status'] = 'publish';
					}
				}

				$post['facebook_id'] = $album->id;
				$post['facebook_raw'] = $album;

				$post['album_photos'] = $this->retrieve_album_photos( $album->id );

				$this->posts[] = $post;
			}
		}
	}

	private function extract_posts_from_data_photos( $importdata ) {
		global $wpdb;

		foreach ( $importdata->data as $photo ) {
			$facebook_id = $photo->id;

			$photo_src = '';

			$post_ids = $wpdb->get_results( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'facebook_id' AND meta_value = %s", $facebook_id ) );

			if ( ! empty( $post_id ) ) {
				foreach ( $post_ids as $post_id ) {
					$post = get_post( $post_id );

					if ( $post->post_type == 'post' )
						continue 2;
					else if ( $post->post_type == 'attachment' )
						$photo_src = wp_get_attachment_image_src( $post_id, 'large' );
				}
			}

			// Create a post and upload the photo for this photo.
			$post = array();
			$post['post_title'] = isset( $photo->name ) ? $photo->name : '';
			$post['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', strtotime( $photo->created_time ) );
			$post['post_date'] = get_date_from_gmt( $post['post_date_gmt'] );
			$post['post_modified_gmt'] = gmdate( 'Y-m-d H:i:s', strtotime( $photo->updated_time ) );
			$post['post_modified'] = get_date_from_gmt( $post['post_modified_gmt'] );
			$post['post_type'] = 'post';
			$post['post_author'] = $this->get_option( 'author' );
			$post['tags'] = $this->get_option( 'tags' );
			$post['post_category'] = array( $this->get_option( 'category' ) );
			$post['post_status'] = $this->get_option( 'fb_post_status' );

			if ( ! $post['post_status'] ) {
				if ( isset( $photo->privacy ) && isset( $photo->privacy->value ) && ! empty( $photo->privacy->value ) ) {
					$post['post_status'] = 'private';
				}
				else {
					$post['post_status'] = 'publish';
				}
			}

			$post['facebook_id'] = $photo->id;
			$post['facebook_raw'] = $photo;

			if ( $photo_src ) {
				$post['post_content'] = $photo_src;
			}
			else {
				$post['photos'] = array( $photo->images[0]->source );
			}

			$this->posts[] = $post;
		}
	}

	function insert_posts() {
		global $wpdb;
		$imported = 0;
		$skipped  = 0;
		foreach ( $this->posts as $post ) {
			// See the end of extract_posts_from_data() for what is in here
			extract( $post );

			if (
				!$facebook_id
			||
				$wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = 'facebook_id' AND meta_value = %s", $facebook_id ) )
			||
				$post_id = post_exists( $post_title, $post_content, $post_date )
			) {
				// Looks like a duplicate
				$skipped++;
			} else {
				$post = apply_filters( 'keyring_facebook_importer_post', $post );
				
				$post_id = wp_insert_post( $post );

				if ( is_wp_error( $post_id ) )
					return $post_id;

				if ( !$post_id )
					continue;

				$post['ID'] = $post_id;

				// Track which Keyring service was used
				wp_set_object_terms( $post_id, self::LABEL, 'keyring_services' );

				// Mark it as an aside
				set_post_format( $post_id, 'status' );

				// Update Category
				wp_set_post_categories( $post_id, $post_category );

				add_post_meta( $post_id, 'facebook_id', $facebook_id );
				add_post_meta( $post_id, 'endpoint', $this->current_endpoint );

				if ( count( $tags ) )
					wp_set_post_terms( $post_id, implode( ',', $tags ) );

				// Store geodata if it's available
				if ( !empty( $geo ) ) {
					add_post_meta( $post_id, 'geo_latitude', $geo['lat'] );
					add_post_meta( $post_id, 'geo_longitude', $geo['long'] );
					add_post_meta( $post_id, 'geo_public', 1 );
				}

				add_post_meta( $post_id, 'raw_import_data', json_encode( $facebook_raw ) );

				if ( ! empty( $photos ) ) {
					$this->sideload_media( $photos, $post_id, $post, apply_filters( 'keyring_facebook_importer_image_embed_size', 'full' ) );
				}

				if ( ! empty( $album_photos ) ) {
					foreach ( $album_photos as $photo ) {
						$this->sideload_photo_to_album( $photo, $post_id );
					}
				}

				if ( ! empty( $videos ) ) {
					foreach ( $videos as $video ) {
						// $this->sideload_media( $video, $post_id, $post, 'full' );
						$this->sideload_video( $video, $post_id );
					}
				}

				$imported++;

				do_action( 'keyring_post_imported', $post_id, static::SLUG, $post );
			}
		}
		$this->posts = array();

		// Return, so that the handler can output info (or update DB, or whatever)
		return array( 'imported' => $imported, 'skipped' => $skipped );
	}

	private function rotate_endpoint() {
		$this->set_option( 'endpoint_index', ( ( $this->get_option( 'endpoint_index', 0 ) + 1 ) % count( $this->api_endpoints ) ) );
		$this->current_endpoint = $this->endpoint_prefix . $this->api_endpoints[ $this->get_option( 'endpoint_index' ) ];
	}

	function sideload_video( $url, $post_id ) {
		$file = array();
		$file['tmp_name'] = download_url( $url );
		if ( is_wp_error( $file['tmp_name'] ) ) {
			// Download failed, leave the post alone
			@unlink( $file_array['tmp_name'] );
		} else {
			// Download worked, now import into Media Library
			$file['name'] = substr(basename($url), 0, strpos(basename($url), '?'));
			$id = media_handle_sideload( $file, $post_id );
			@unlink( $file_array['tmp_name'] );
			if ( ! is_wp_error( $id ) ) {
				// Update URL in post to point to the local copy
				$post_data = get_post( $post_id );
				$post_data->post_content = str_replace( $url, wp_get_attachment_url( $id ), $post_data->post_content );
				wp_update_post( $post_data );
			}
		}
	}

	private function sideload_album_photo( $file, $post_id, $desc = '' ) {
		if ( !function_exists( 'media_handle_sideload' ) )
			require_once ABSPATH . 'wp-admin/includes/media.php';
		if ( !function_exists( 'download_url' ) )
			require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( !function_exists( 'wp_read_image_metadata' ) )
			require_once ABSPATH . 'wp-admin/includes/image.php';

		/* Taken from media_sideload_image. There's probably a better way that doesn't include so much copy/paste. */
		// Download file to temp location
		$tmp = download_url( $file );
		// Set variables for storage
		// fix file filename for query strings
		preg_match( '/[^\?]+\.(jpe?g|jpe|gif|png)\b/i', $file, $matches );
		$file_array['name'] = basename($matches[0]);
		$file_array['tmp_name'] = $tmp;
		// If error storing temporarily, unlink
		if ( is_wp_error( $tmp ) ) {
				@unlink($file_array['tmp_name']);
				$file_array['tmp_name'] = '';
		}
		// do the validation and storage stuff
		$id = media_handle_sideload( $file_array, $post_id, $desc );
		/* End copy/paste */

		@unlink($file_array['tmp_name']);

		return $id;
	}

	private function retrieve_pages() {
		$api_url = "https://graph.facebook.com/me/accounts?fields=id,name,category";

		$pages = array();

		$pages_data = $this->service->request( $api_url, array( 'method' => 'GET', 'timeout' => 10 ) );

		if ( empty( $pages_data ) || empty( $pages_data->data ) ) {
			return false;
		}

		foreach ( $pages_data->data as $page_data ) {
			$page = array();
			$page['id'] = $page_data->id;
			$page['name'] = $page_data->name;
			$page['category'] = $page_data->category;

			$pages[] = $page;
		}

		return $pages;
	}

	private function retrieve_album_photos( $album_id, $since = null ) {
		// Get photos
		$api_url = "https://graph.facebook.com/" . $album_id . "/photos?fields=id,name,link,images,created_time,updated_time";

		$photos = array();

		while ( $api_url = $this->_retrieve_album_photos( $api_url, $photos ) );

		return $photos;
	}

	private function _retrieve_album_photos( $api_url, &$photos ) {
		$album_data = $this->service->request( $api_url, array( 'method' => $this->request_method, 'timeout' => 10 ) );

		if ( empty( $album_data ) || empty( $album_data->data ) ) {
			return false;
		}

		foreach ( $album_data->data as $photo_data ) {

			$photo = array();
			$photo['post_title'] = $photo_data->name;
			$photo['src'] = $photo_data->images[0]->source;

			$photo['facebook_raw'] = $photo_data;
			$photo['facebook_id'] = $photo_data->id;

			$photo['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', strtotime( $photo_data->created_time ) );
			$photo['post_date'] = get_date_from_gmt( $post['post_date_gmt'] );
			$photo['post_modified_gmt'] = gmdate( 'Y-m-d H:i:s', strtotime( $photo_data->updated_time ) );
			$photo['post_modified'] = get_date_from_gmt( $post['post_modified_gmt'] );

			$photos[] = $photo;
		}

		if ( isset( $album_data->paging ) && ! empty( $album_data->paging->next ) )
			return $album_data->paging->next;

		return false;
	}

	private function sideload_photo_to_album( $photo, $album_id ) {
		global $wpdb;
		
		$photo_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'facebook_id' AND meta_value = %s", $photo['facebook_id'] ) );

		if ( ! $photo_id ) {
			$photo_id = $this->sideload_album_photo( $photo['src'], $album_id, $photo['post_title'] );

			add_post_meta( $photo_id, 'facebook_id', $photo['facebook_id'] );
			add_post_meta( $photo_id, 'raw_import_data', json_encode( $photo['facebook_raw'] ) );
		}
		else {
			$photo_post = get_post( $photo_id );
			$photo_post->post_parent = $album_id;
			wp_update_post( (array) $photo_post );
		}

		return $photo_id;
	}
}

} // end function Keyring_Facebook_Importer


add_action( 'init', function() {
	Keyring_Facebook_Importer(); // Load the class code from above
	keyring_register_importer(
		'facebook',
		'Keyring_Facebook_Importer',
		plugin_basename( __FILE__ ),
		__( 'Download all of your Facebook statuses as individual Posts (with a "status" post format).', 'keyring' )
	);
} );

add_filter( 'keyring_facebook_scope', function ( $scopes ) {
	$scopes[] = 'user_posts';
	$scopes[] = 'user_photos';
	// $scopes[] = 'manage_pages';
	return $scopes;
} );
