<?php

function Keyring_Facebook_Importer() {

	class Keyring_Facebook_Importer extends Keyring_Importer_Base {
		const SLUG              = 'facebook';    // e.g. 'twitter' (should match a service in Keyring)
		const LABEL             = 'Facebook';    // e.g. 'Twitter'
		const KEYRING_SERVICE   = 'Keyring_Service_Facebook';    // Full class name of the Keyring_Service this importer requires
		const REQUESTS_PER_LOAD = 1;     // How many remote requests should be made before reloading the page?
		const REQUEST_TIMEOUT   = 600; // Number of seconds to wait before another request
		const LOG_PATH          = '/tmp/log.txt';

		/**
		 * @var array Endpoints.
		 */
		private $api_endpoints = array(
			'/posts',
			// '/albums',
			// '/photos',
		);

		/**
		 * @var array Endpoint fields.
		 */
		private $api_endpoint_fields = array(
			'/posts'  => 'id,object_id,created_time,updated_time,name,message,description,story,link,source,picture,full_picture,attachments,permalink_url,type,comments,privacy,place&until=2021-05-31',
			'/albums' => 'id,name,created_time,updated_time,privacy,type',
			'/photos' => 'id,name,created_time,updated_time,images',
		);

		/**
		 * @var string Current endpoint.
		 */
		private $current_endpoint = null;

		/**
		 * @var string Endpoint prefix.
		 */
		private $endpoint_prefix = null;

		/**
		 * @author cfinke <https://github.com/cfinke>
		 */
		public function __construct() {
			$this->log(__METHOD__);

			$rv = parent::__construct();

			if ($this->get_option('facebook_page', '')) {
				$this->endpoint_prefix = $this->get_option('facebook_page');
			} else {
				$this->endpoint_prefix = 'me';
			}

			$this->current_endpoint = $this->endpoint_prefix . $this->api_endpoints[min(count($this->api_endpoints) - 1, $this->get_option('endpoint_index', 0))];
			add_action('keyring_importer_facebook_custom_options', array($this, 'custom_options'));

			return $rv;
		}

		/**
		 * @author cfinke <https://github.com/cfinke>
		 * @author wayubi <https://github.com/wayubi>
		 */
		public function handle_request_options() {
			$this->log(__METHOD__);

			// Validate options and store them so they can be used in auto-imports
			if (empty($_POST['category']) || !ctype_digit($_POST['category']))
				$this->error(__("Make sure you select a valid category to import your statuses into."));

			if (empty($_POST['author']) || !ctype_digit($_POST['author']))
				$this->error(__("You must select an author to assign to all statuses."));

			if (isset($_POST['auto_import']))
				$_POST['auto_import'] = true;
			else
				$_POST['auto_import'] = false;

			// If there were errors, output them, otherwise store options and start importing
			if (count($this->errors)) {
				$this->step = 'options';
			} else {
				$this->set_option(array(
					'category'                 => (int) $_POST['category'],
					'tags'                     => explode(',', $_POST['tags']),
					'author'                   => (int) $_POST['author'],
					'auto_import'              => $_POST['auto_import'],
					'facebook_page'            => $_POST['facebook_page'],
					'fb_post_status'           => $_POST['fb_post_status'],
					'import_private_posts'     => $_POST['import_private_posts'],
					'cache_album_images_reset' => $_POST['cache_album_images_reset'],
					'comment_trigger'          => $_POST['comment_trigger']
				));

				$this->step = 'import';
			}
		}

		/**
		 * @author cfinke <https://github.com/cfinke>
		 * @author wayubi <https://github.com/wayubi>
		 */
		public function custom_options() {
			$this->log(__METHOD__);

			?>
			<tr valign="top">
				<th scope="row">
					<label for="include_rts"><?php _e('Post Status', 'keyring-facebook'); ?></label>
				</th>
				<td>
					<?php
						$prev_post_status = $this->get_option('fb_post_status');
					?>
					<select name="fb_post_status" id="fb_post_status">
						<option value="publish" <?php selected($prev_post_status == 'publish'); ?>><?php esc_html_e('Publish', 'keyring-facebook'); ?></option>
						<option value="private" <?php selected($prev_post_status == 'private'); ?>><?php esc_html_e('Private', 'keyring-facebook'); ?></option>
					</select>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row">
					<label for="include_rts"><?php esc_html_e('Import From', 'keyring-facebook'); ?></label>
				</th>
				<td>
					<?php
						$prev_fb_page = $this->get_option('facebook_page');
						$fb_pages = $this->retrieve_pages();
					?>
					<select name="facebook_page" id="facebook_page">
						<option value="0"><?php esc_html_e('Personal Profile', 'keyring-facebook'); ?></option>
						<?php
							if (!empty($fb_pages) && is_array($fb_pages)) {
								foreach ($fb_pages as $fb_page) {
									printf('<option value="%1$s"' . selected($prev_fb_page == $fb_page['id']) . '>%2$s</option>', esc_attr($fb_page['id']), esc_html($fb_page['name']));
								}
							}
						?>
					</select>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row">
					<label for="include_rts"><?php esc_html_e('Import private posts', 'keyring-facebook'); ?></label>
				</th>
				<td>
					<?php
						$import_private_posts = $this->get_option('import_private_posts');
					?>
					<select name="import_private_posts" id="import_private_posts">
						<option value="1" <?php selected($import_private_posts == '1'); ?>><?php esc_html_e('Yes', 'keyring-facebook'); ?></option>
						<option value="0" <?php selected($import_private_posts == '0'); ?>><?php esc_html_e('No', 'keyring-facebook'); ?></option>
					</select>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row">
					<label for="include_rts"><?php esc_html_e('Reset album images cache', 'keyring-facebook'); ?></label>
				</th>
				<td>
					<select name="cache_album_images_reset" id="cache_album_images_reset">
						<option value="0" selected><?php esc_html_e('No', 'keyring-facebook'); ?></option>
						<option value="1"><?php esc_html_e('Yes', 'keyring-facebook'); ?></option>
					</select>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row">
					<label for="include_rts"><?php esc_html_e('Comment Trigger', 'keyring-facebook'); ?></label>
				</th>
				<td>
					<?php
						$prev_comment_trigger = $this->get_option('comment_trigger');
					?>
					<input type="text" class="regular-text" name="comment_trigger" id="comment_trigger" value="<?php echo esc_html($prev_comment_trigger); ?>" />
					<p class="description"><?php _e('Initial text at the beginning of the comment that triggers that comment to be imported.', 'keyring'); ?></p>
				</td>
			</tr>
			<?php
		}

		/**
		 * @author cfinke <https://github.com/cfinke>
		 * @author wayubi <https://github.com/wayubi>
		 */
		public function build_request_url() {
			$this->log(__METHOD__);

			$endpoint_prefix_length = strlen($this->endpoint_prefix);
			$endpoint = substr($this->current_endpoint, $endpoint_prefix_length);

			// Base request URL
			$url = "https://graph.facebook.com/" . $this->current_endpoint . "?fields=" . $this->api_endpoint_fields[$endpoint];
			// var_dump($url);
			return $url;

			if ($this->auto_import) {
				// Get most recent checkin we've imported (if any), and its date so that we can get new ones since then
				$latest = get_posts(array(
					'numberposts' => 1,
					'orderby'     => 'date',
					'order'       => 'DESC',
					'meta_key'    => 'endpoint',
					'meta_value'  => $this->current_endpoint,
					'tax_query'   => array(array(
						'taxonomy' => 'keyring_services',
						'field'    => 'slug',
						'terms'    => array($this->taxonomy->slug),
						'operator' => 'IN',
					)),
				));

				// If we have already imported some, then start since the most recent
				if ($latest) {
					$url = add_query_arg('since', strtotime($latest[0]->post_date_gmt) + 1, $url);
				}
			} else {
				// Handle page offsets (only for non-auto-import requests)
				$url = $this->get_option('paging:' . $this->current_endpoint, $url);
			}

			return $url;
		}

		/**
		 * Grab a chunk of data from the remote service and process it into posts, and handle actually importing as well.
		 * Keeps track of 'state' in the DB.
		 * 
		 * @author beaulebens <https://github.com/beaulebens>
		 * @author wayubi <https://github.com/wayubi>
		 */
		public function import() {
			$this->log(__METHOD__);

			defined('WP_IMPORTING') or define('WP_IMPORTING', true);
			do_action('import_start');
			$num = 0;
			$this->header();
			$stop_after_import_requests = apply_filters('keyring_importer_stop_after_import_requests', false);

			echo '<p>' . __('Importing Posts...', 'keyring') . '</p>';
			echo '<ol>';
			while (! $this->finished && $num < static::REQUESTS_PER_LOAD) {
				$data = $this->make_request();
				if (Keyring_Util::is_error($data)) {
					return $data;
				}

				$result = $this->extract_posts_from_data($data);
				if (Keyring_Util::is_error($result)) {
					return $result;
				}

				// Use this filter to modify any/all posts before they are actually inserted as posts
				$this->posts = apply_filters('keyring_importer_posts_pre_insert', $this->posts, $this->service->get_name());

				$result = $this->insert_posts();
				if (Keyring_Util::is_error($result)) {
					return $result;
				} else {
					echo '<li>' . sprintf(__('Imported %d posts in this batch', 'keyring'), $result['imported']) . ($result['skipped'] ? sprintf(__(' (skipped %d that looked like duplicates).', 'keyring'), $result['skipped']) : '.') . '</li>';
					flush();
					$this->set_option('imported', ($this->get_option('imported') + $result['imported']));
				}

				if ($stop_after_import_requests && ($this->get_option('imported') >= $stop_after_import_requests)) {
					$this->finished = true;
					break; // Break to avoid incrementing `page`
				}

				// Keep track of which "page" we're up to
				$this->set_option('page', $this->get_option('page') + 1);

				// Local (per-page-load) counter
				$num++;
			}
			echo '</ol>';
			$this->footer();

			if ($this->finished) {
				$this->importer_goto('done', 1);
			} else {
				$this->importer_goto('import', static::REQUEST_TIMEOUT);
			}

			do_action('import_end');

			return true;
		}

		/**
		 * @author cfinke <https://github.com/cfinke>
		 */
		public function extract_posts_from_data($raw) {
			$this->log(__METHOD__);

			global $wpdb;

			$importdata = $raw;

			if (null === $importdata) {
				$this->finished = true;
				return new Keyring_Error('keyring-facebook-importer-failed-download', __('Failed to download your statuses from Facebook. Please wait a few minutes and try again.'));
			}

			// Make sure we have some statuses to parse
			if (!is_object($importdata) || !count($importdata->data)) {
				if ($this->get_option('endpoint_index') == (count($this->api_endpoints) - 1))
					$this->finished = true;

				$this->set_option('paging:' . $this->current_endpoint, null);
				$this->rotate_endpoint();
				return;
			}

			switch ($this->current_endpoint) {
				case $this->endpoint_prefix . '/posts':
					$this->extract_posts_from_data_posts($importdata);
				break;
				case $this->endpoint_prefix . '/albums':
					$this->extract_posts_from_data_albums($importdata);
				break;
				case $this->endpoint_prefix . '/photos':
					$this->extract_posts_from_data_photos($importdata);
				break;
			}

			if (isset($importdata->paging) && isset($importdata->paging->next)) {
				$this->set_option('paging:' . $this->current_endpoint, $importdata->paging->next);
			}
			else {
				if ($this->get_option('endpoint_index') == (count($this->api_endpoints) - 1))
					$this->finished = true;

				$this->set_option('paging:' . $this->current_endpoint, null);
				$this->rotate_endpoint();
			}
		}

		/**
		 * @author cfinke <https://github.com/cfinke>
		 * @author wayubi <https://github.com/wayubi>
		 */
		private function extract_posts_from_data_posts($importdata) {
			$this->log(__METHOD__);
			global $wpdb;

			$cache_album_images = $this->cache_album_images();

			$import_private_posts = (bool) $this->get_option('import_private_posts');

			foreach ($importdata->data as $post) {

				if (!$import_private_posts && !empty($post->privacy) && !empty($post->privacy->value) && $post->privacy->value == 'SELF')
					continue;

				$facebook_id = substr($post->id, strpos($post->id, '_') + 1);
				$import_url = $post->permalink_url;

				$post_id = $wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = 'facebook_id' AND meta_value = %s", $facebook_id));

				// Other bits
				$post_author = $this->get_option('author');
				$post_status = $this->get_option('fb_post_status');

				if ($post_id)
					continue;

				$facebook_raw = $post;

				// Parse/adjust dates
				$post_date_gmt = gmdate('Y-m-d H:i:s', strtotime($post->created_time));
				$post_date = get_date_from_gmt($post_date_gmt);

				if (!empty($post->name)) {

					if ($post->name == 'Mobile Uploads') unset($post->name);
					if ($post->name == 'Timeline Photos') unset($post->name);

					if ((bool) preg_match('/^.*?\scover photo$/', $post->name)) {
						$post->message = 'Cover';
						unset($post->name);
					}

					if ((bool) preg_match('/^Photos\sfrom\s.*?\spost$/', $post->name)) {
						if (empty($post->message)) $post->message = 'Photos';
						unset($post->name);
					}

					if ((bool) preg_match('/^' . preg_quote($post->name, '/'). '.*?$/', $this->service->get_token()->get_display())) unset($post->name);
				}

				// Prepare media

				$videos = array();
				$photos = array();

				if (!empty($post->attachments) && !empty($post->attachments->data)) {

					foreach ($post->attachments->data as $data) {

						if ($data->type == 'album' && !empty($data->target)) {

							if (!empty($data->subattachments) && !empty($data->subattachments->data)) {

								foreach ($data->subattachments->data as $s_data) {
		
									if ($s_data->type == 'photo') {
										if (array_key_exists($s_data->target->id, $cache_album_images)) {
											$this->log(__METHOD__ . ': cache_album_images : ' . $s_data->target->id);
											$photos[] = $cache_album_images[$s_data->target->id];
										} else {
											$this->log(__METHOD__ . ': service->request>images : ' . $s_data->target->id);
											$photo_object = $this->service->request('https://graph.facebook.com/' . $s_data->target->id . '?fields=images');
											$photos[] = $this->fetchHighResImage($photo_object->images);
										}
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
							}

						} else if ($data->type == 'goodwill_shared_card') {
							$post->name = $data->title;
							$post->link = $data->url;
						} else if ($data->type == 'year_in_review') {
							$post->name = 'Year in Review';
							$post->link = $data->url;
						} else if ($data->type == 'profile_media' || $data->title == 'Profile Pictures') {
							$post->message = 'Profile';
							$photo_object = $this->service->request('https://graph.facebook.com/' . $post->object_id . '?fields=images');
							$photos[] = $this->fetchHighResImage($photo_object->images);
						} else if ($data->type == 'photo') {
							if (array_key_exists($data->target->id, $cache_album_images)) {
								$this->log(__METHOD__ . ': cache_album_images : ' . $data->target->id);
								$photos[] = $cache_album_images[$data->target->id];
							} else {
								$this->log(__METHOD__ . ': service->request>images : ' . $data->target->id);
								$photo_object = $this->service->request('https://graph.facebook.com/' . $data->target->id . '?fields=images');
								$photos[] = $this->fetchHighResImage($photo_object->images);
							}
						} else if (($data->type == 'video_inline' || $data->type == 'animated_image_video') && !empty($data->media->source)) {
							$this->log(__METHOD__ . ': service->request>videos : ' . $data->target->id);
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
						} else if ($data->type == 'cover_photo') {
							if (array_key_exists($post->object_id, $cache_album_images)) {
								$this->log(__METHOD__ . ': cache_album_images : ' . $post->object_id);
								$photos[] = $cache_album_images[$post->object_id];
							} else {
								$this->log(__METHOD__ . ': service->request>images : ' . $post->object_id);
								$photo_object = $this->service->request('https://graph.facebook.com/' . $post->object_id . '?fields=images');
								$photos[] = $this->fetchHighResImage($photo_object->images);
							}
						} else if ($data->type == 'map') {
							$message = 'Went to ' . $data->title . '.';
							if (!empty($post->message))
								$message .= PHP_EOL . PHP_EOL . $post->message;
							$post->message = $message;
							$photos[] = $data->media->image->src;
						} else if ($data->type == 'image_share') {
							$photos[] = $post->link;
						} else {
							if (!empty($post->full_picture))
								$photos[] = $post->full_picture;
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

				// Prepare post title

				$post_title = '';
				if (!empty($post->message))
					$post_title = $post->message;
				else if (!empty($post->story))
					$post_title = $post->story;
				else if (!empty($post->name))
					$post_title = $post->name;
				else {
					$post->message = 'Untitled';
					$post_title = $post->message;
				}

				$post_title = $this->prepare_post_title($post_title);

				// Prepare post body

				$post_content = '';

				// Inject first image
				if (!empty($photos)) {
					if (!empty($videos) || stristr($post->link, 'youtube.com')) {
						$post_content .= '<p class="vthumb"><img src="' . $photos[0] . '" /></p>' . PHP_EOL . PHP_EOL;
					} else {
						$post_content .= '<img src="' . $photos[0] . '" />' . PHP_EOL . PHP_EOL;
					}
				}

				// Insert first video
				if (!empty($videos)) { // Embedded
					$post_content .= '[video src="' . esc_url($videos[0]) . '" loop="on"]' . PHP_EOL . PHP_EOL;
				} else if (stristr($post->link, 'youtube.com')) { // YouTube
					$matches = array();
					if ((bool) preg_match('/attribution_link.*?v=([\d\w\-\_]+)/', urldecode($post->link), $matches)) {
						$post->link = 'https://www.youtube.com/watch?v=' . $matches[1];
						$post_content .= $post->link . PHP_EOL . PHP_EOL;
					} else if ((bool) preg_match('/youtube\.com.*?v=([\d\w\-\_]+)/', $post->link, $matches)) {
						$post_content .= 'https://www.youtube.com/watch?v=' . $matches[1] . PHP_EOL . PHP_EOL;
					}
				}

				// Inject remaining images
				foreach ($photos as $index => $photo) {
					if ($index == 0)
						continue;
					$post_content .= '<img src="' . $photo . '" />' . PHP_EOL . PHP_EOL;
				}

				// Inject remaining videos
				foreach ($videos as $index => $video) {
					if ($index == 0)
						continue;
					$post_content .= '[video src="' . esc_url($video) . '" loop="on"]' . PHP_EOL . PHP_EOL;
				}

				// Continue with text
				if (!empty($post->story))
					$post_content .= $this->make_clickable($post->story, array('twitter.com', 'youtube.com')) . PHP_EOL . PHP_EOL;

				if (!empty($post->message)) {
					$message = $post->message;
					$message = preg_replace('/(https{0,1}:\/\/www.facebook.com).+?(posts\/\d+)/', '$1/' . $this->service->get_token()->get_meta('user_id') . '/$2', $message);
					$post_content .= $this->make_clickable($message, array('twitter.com', 'youtube.com')) . PHP_EOL . PHP_EOL;
				}

				// Prepare comments
				$comment_trigger = $this->get_option('comment_trigger');

				if (!empty($comment_trigger)) {
					if (!empty($post->comments)) {
						foreach ($post->comments->data as $data) {

							if (substr($data->message, 0, strlen($comment_trigger)) != $comment_trigger)
								continue;

							$this->log(__METHOD__ . ': service->request>comments : ' . $data->id);
							$comment_object = $this->service->request('https://graph.facebook.com/' . $data->id . '?fields=attachment');
							if (!empty($comment_object->attachment)) {
								$attachment = $comment_object->attachment;

								if ($attachment->type == 'photo') {
									$this->log(__METHOD__ . ': service->request>comments/images : ' . $attachment->target->id);
									$photo_object = $this->service->request('https://graph.facebook.com/' . $attachment->target->id . '?fields=images');
									$image = $this->fetchHighResImage($photo_object->images);
									$photos[] = $image;
									$post_content .= '<img src="' . $image . '" />' . PHP_EOL . PHP_EOL;
								} else if ($attachment->type == 'video_inline') {
									$this->log(__METHOD__ . ': service->request>comments/videos : ' . $attachment->target->id);
									$video_object = $this->service->request('https://graph.facebook.com/' . $attachment->target->id . '?fields=source');
									$videos[] = $video_object->source;
									$post_content .= '[video src="' . esc_url($video_object->source) . '" loop="on"]' . PHP_EOL . PHP_EOL;
								}
							}

							$message = ltrim(substr($data->message, strlen($comment_trigger)));
							if (!empty($message)) {
								// $message = preg_replace('/\n\n/', '</p><p>', $message);
								$post_content .= $this->make_clickable($message, array('twitter.com', 'youtube.com')) . PHP_EOL . PHP_EOL;
							}
						}
					}
				}

				// Prepare place
				if (!empty($post->place) && !empty($post->place->location) && !empty($post->place->location->latitude) && !empty($post->place->location->longitude)) {
					$place_name = !empty($post->place->name) ? $post->place->name : $post->place->location->latitude . ',' . $post->place->location->longitude;
					$bbox = $this->getOpenStreetMapBBox($post->place->location->latitude, $post->place->location->longitude, 10000);
					$openStreetMapUrl = vsprintf('https://www.openstreetmap.org/export/embed.html?bbox=%.15f%%2C%.15f%%2C%.15f%%2C%.15f&amp;layer=mapnik&amp;marker=%.15f%%2C%.15f', $bbox);
					$post_content .= '<iframe width="425" height="350" frameborder="0" scrolling="no" marginheight="0" marginwidth="0" src="' . $openStreetMapUrl . '" style="border: 1px solid black"></iframe>' . PHP_EOL . PHP_EOL;
					$post_content .= '<a href="https://www.openstreetmap.org/#map=16/' . $post->place->location->latitude . '/' . $post->place->location->longitude . '">' . $place_name . '</a>' . PHP_EOL . PHP_EOL;
				}

				// Prepare blockquote
				if (
					$post->link != $post->permalink_url
					&& (!empty($post->name) || !empty($post->description))
					&& !in_array(pathinfo($post->link)['extension'], array('jpg', 'jpg:large', 'png', 'png:large'))
				) {
					$post_content .= '<blockquote>';

					if (!empty($post->name)) {
						if (!empty($post->description)) {
							if (($this->prepare_post_title($post->name) != $this->prepare_post_title($post->description))) {
								$post_content .= $post->name . PHP_EOL . PHP_EOL;
							}
						} else {
							$post_content .= $post->name . PHP_EOL . PHP_EOL;
						}
					}

					if (!empty($post->description))
						$post_content .= $this->make_clickable($post->description, array('twitter.com', 'youtube.com')) . PHP_EOL . PHP_EOL;

					if (!empty($post->link))
						$post_content .= $this->make_clickable($post->link, array('twitter.com')) . PHP_EOL . PHP_EOL;

					$post_content .= '</blockquote>';
				}

				// Prepare tags
				$tags = $this->get_option('tags');

				// Apply selected category
				$post_category = array($this->get_option('category'));

				// Other bits
				$post_author = $this->get_option('author');
				$post_status = $this->get_option('fb_post_status');

				if (! $post_status) {
					if (isset($post->privacy) && isset($post->privacy->value) && ! empty($post->privacy->value)) {
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
					'import_url',
					'tags',
					'facebook_raw',
					'photos',
					'videos'
				);

				$this->posts[] = $compact;
			}
		}

		/**
		 * @todo do $import_url
		 * 
		 * @author cfinke <https://github.com/cfinke>
		 * @author wayubi <https://github.com/wayubi>
		 */
		private function extract_posts_from_data_albums($importdata) {
			$this->log(__METHOD__);

			global $wpdb;

			$import_private_posts = (bool) $this->get_option('import_private_posts');

			foreach ($importdata->data as $album) {

				if (!$import_private_posts && !empty($album->privacy) && $album->privacy == 'custom')
					continue;

				$facebook_id = $album->id;

				$post_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'facebook_id' AND meta_value = %s", $facebook_id));

				if ($post_id) {
					$original_post = get_post($post_id);

					// Pull in any photos added since we last updated the album.
					if (strtotime($original_post->post_modified_gmt) < strtotime($album->updated_time)) {
						$new_photos = $this->retrieve_album_photos($album->id, strtotime($original_post->post_modified_gmt));

						foreach ($new_photos as $photo) {
							$this->sideload_photo_to_album($photo, $post_id);
						}

						$original_post->post_modified_gmt = gmdate('Y-m-d H:i:s', strtotime($album->updated_time));
						$original_post->post_modified = get_date_from_gmt($post->post_modified_gmt);
						wp_update_post((array) $original_post);
					}
				}
				else {
					// Create a post for this gallery.
					$post = array();
					$post['post_title'] = $album->name;
					$post['post_content'] = '[gallery order="DESC" orderby="date"]';
					$post['post_date_gmt'] = gmdate('Y-m-d H:i:s', strtotime($album->created_time));
					$post['post_date'] = get_date_from_gmt($post['post_date_gmt']);
					$post['post_modified_gmt'] = gmdate('Y-m-d H:i:s', strtotime($album->updated_time));
					$post['post_modified'] = get_date_from_gmt($post['post_modified_gmt']);
					$post['post_type'] = 'post';
					$post['post_author'] = $this->get_option('author');

					$tags = $this->get_option('tags');
					$tags[] = 'albums';
					$post['tags'] = $tags;

					$post['post_category'] = array($this->get_option('category'));
					$post['post_status'] = $this->get_option('fb_post_status');

					if (! $post['post_status']) {
						if (isset($album->privacy) && isset($album->privacy->value) && ! empty($album->privacy->value)) {
							$post['post_status'] = 'private';
						}
						else {
							$post['post_status'] = 'publish';
						}
					}

					$post['facebook_id'] = $album->id;
					$post['facebook_raw'] = $album;

					$post['album_photos'] = $this->retrieve_album_photos($album->id);

					$this->posts[] = $post;
				}
			}
		}

		/**
		 * @todo skip $import_private_posts
		 * @todo do $import_url
		 * 
		 * @author cfinke <https://github.com/cfinke>
		 * @author wayubi <https://github.com/wayubi>
		 */
		private function extract_posts_from_data_photos($importdata) {
			$this->log(__METHOD__);
			global $wpdb;

			foreach ($importdata->data as $photo) {

				$facebook_id = $photo->id;

				$post_id = $wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = 'facebook_id' AND meta_value = %s", $facebook_id));

				if ($post_id)
					continue;

				// Create a post and upload the photo for this photo.
				$post = array();
				$post['post_title'] = $this->prepare_post_title(!empty($photo->name) ? $photo->name : 'Untitled');
				$post['post_date_gmt'] = gmdate('Y-m-d H:i:s', strtotime($photo->created_time));
				$post['post_date'] = get_date_from_gmt($post['post_date_gmt']);
				$post['post_modified_gmt'] = gmdate('Y-m-d H:i:s', strtotime($photo->updated_time));
				$post['post_modified'] = get_date_from_gmt($post['post_modified_gmt']);
				$post['post_type'] = 'post';
				$post['post_author'] = $this->get_option('author');
				$post['tags'] = $this->get_option('tags');
				$post['post_category'] = array($this->get_option('category'));
				$post['post_status'] = $this->get_option('fb_post_status');

				if (! $post['post_status']) {
					if (isset($photo->privacy) && isset($photo->privacy->value) && ! empty($photo->privacy->value)) {
						$post['post_status'] = 'private';
					}
					else {
						$post['post_status'] = 'publish';
					}
				}

				$post['facebook_id'] = $photo->id;
				$post['facebook_raw'] = $photo;

				$post['photos'] = $this->fetchHighResImage($photo->images);

				// Prepare post body

				$post['post_content'] = '';

				if (!empty($post['photos']))
					$post['post_content'] .= '<img src="' . $post['photos'] . '" />' . PHP_EOL . PHP_EOL;

				if (!empty($photo->name))
					$post['post_content'] .= $this->make_clickable($photo->name) . PHP_EOL . PHP_EOL;

				$post['post_content'] .= '<a href="https://www.facebook.com/' . $facebook_id . '">Facebook</a>' . PHP_EOL . PHP_EOL;

				$this->posts[] = $post;
			}
		}

		/**
		 * @author cfinke <https://github.com/cfinke>
		 * @author wayubi <https://github.com/wayubi>
		 */
		public function insert_posts() {
			$this->log(__METHOD__);

			global $wpdb;
			$imported = 0;
			$skipped  = 0;
			foreach ($this->posts as $post) {
				// See the end of extract_posts_from_data() for what is in here
				extract($post);

				if (
					!$facebook_id
					|| $wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = 'facebook_id' AND meta_value = %s", $facebook_id))
					|| $post_id = post_exists($post_title, $post_content, $post_date)
				) { // Looks like a duplicate
					$skipped++;
				} else {
					$post = apply_filters('keyring_facebook_importer_post', $post);
					
					$post_id = wp_insert_post($post);

					if (is_wp_error($post_id))
						return $post_id;

					if (!$post_id)
						continue;

					$post['ID'] = $post_id;

					// Track which Keyring service was used
					wp_set_object_terms($post_id, self::LABEL, 'keyring_services');

					// Mark it as an aside
					set_post_format($post_id, 'status');

					// Update Category
					wp_set_post_categories($post_id, $post_category);

					add_post_meta($post_id, 'facebook_id', $facebook_id);
					add_post_meta($post_id, 'import_url', $import_url);
					add_post_meta($post_id, 'endpoint', $this->current_endpoint);

					if (count($tags))
						wp_set_post_terms($post_id, implode(',', $tags));

					// Store geodata if it's available
					if (!empty($geo)) {
						add_post_meta($post_id, 'geo_latitude', $geo['lat']);
						add_post_meta($post_id, 'geo_longitude', $geo['long']);
						add_post_meta($post_id, 'geo_public', 1);
					}

					add_post_meta($post_id, 'raw_import_data', json_encode($facebook_raw));

					if (!empty($photos)) {
						$this->sideload_media($photos, $post_id, $post, apply_filters('keyring_facebook_importer_image_embed_size', 'full'));
					}

					if (!empty($videos)) {
						$this->sideload_video($videos, $post_id);
					}

					if (! empty($album_photos)) {
						foreach ($album_photos as $photo) {
							$this->sideload_photo_to_album($photo, $post_id);
						}
					}

					$imported++;

					do_action('keyring_post_imported', $post_id, static::SLUG, $post);
				}
			}
			$this->posts = array();

			// Return, so that the handler can output info (or update DB, or whatever)
			return array('imported' => $imported, 'skipped' => $skipped);
		}

		/**
		 * @author cfinke <https://github.com/cfinke>
		 */
		private function rotate_endpoint() {
			$this->log(__METHOD__);

			$this->set_option('endpoint_index', (($this->get_option('endpoint_index', 0) + 1) % count($this->api_endpoints)));
			$this->current_endpoint = $this->endpoint_prefix . $this->api_endpoints[ $this->get_option('endpoint_index') ];
		}

		/**
		 * @author cfinke <https://github.com/cfinke>
		 * @author wayubi <https://github.com/wayubi>
		 */
		private function retrieve_pages() {
			$this->log(__METHOD__);

			$api_url = "https://graph.facebook.com/me/accounts?fields=id,name,category";

			$pages = array();

			$pages_data = $this->service->request($api_url, array('method' => 'GET', 'timeout' => 10));

			if (empty($pages_data) || empty($pages_data->data)) {
				return false;
			}

			foreach ($pages_data->data as $page_data) {
				$page = array();
				$page['id'] = $page_data->id;
				$page['name'] = $page_data->name;
				$page['category'] = $page_data->category;

				$pages[] = $page;
			}

			return $pages;
		}

		/**
		 * @author cfinke <https://github.com/cfinke>
		 * @author wayubi <https://github.com/wayubi>
		 */
		private function retrieve_album_photos($album_id, $since = null) {
			$this->log(__METHOD__);

			// Get photos
			$api_url = "https://graph.facebook.com/" . $album_id . "/photos?fields=id,name,link,images,created_time,updated_time";

			$photos = array();

			while ($api_url = $this->_retrieve_album_photos($api_url, $photos, $since));

			return $photos;
		}

		/**
		 * @author cfinke <https://github.com/cfinke>
		 * @author wayubi <https://github.com/wayubi>
		 */
		private function _retrieve_album_photos($api_url, &$photos, $since = null) {
			$this->log(__METHOD__);

			$album_data = $this->service->request($api_url, array('method' => $this->request_method, 'timeout' => 10));

			if (empty($album_data) || empty($album_data->data)) {
				return false;
			}

			foreach ($album_data->data as $photo_data) {

				if ($since < strtotime($photo_data->updated_time)) {

					$photo = array();
					$photo['post_title'] = !empty($photo_data->name) ? $photo_data->name : 'Untitled';
					$photo['src'] = $photo_data->images[0]->source;

					$photo['facebook_raw'] = $photo_data;
					$photo['facebook_id'] = $photo_data->id;

					$photo['post_date_gmt'] = gmdate('Y-m-d H:i:s', strtotime($photo_data->created_time));
					$photo['post_date'] = get_date_from_gmt($photo['post_date_gmt']);
					$photo['post_modified_gmt'] = gmdate('Y-m-d H:i:s', strtotime($photo_data->updated_time));
					$photo['post_modified'] = get_date_from_gmt($photo['post_modified_gmt']);

					$photos[] = $photo;
					
				} else {
					return false;
				}
			}

			if (isset($album_data->paging) && ! empty($album_data->paging->next))
				return $album_data->paging->next;

			return false;
		}

		/**
		 * This is a helper for downloading/attaching/inserting media into a post when it's
		 * being imported. See Flickr/Instagram for examples.
		 * 
		 * @param Array of $urls
		 * @param Int post ID
		 * @param Array post object
		 * @param String size of images (large, medium, small)
		 * @param String what to do with the images. Always updated inline. Optionally append/prepend if not found in content
		 *
		 * @author beaulebens <https://github.com/beaulebens>
		 * @author wayubi <https://github.com/wayubi>
		 */
		public function sideload_media($urls, $post_id, $post, $size = 'large', $where = 'prepend') {
			$this->log(__METHOD__);

			if (! function_exists('media_sideload_image')) {
				require_once ABSPATH . 'wp-admin/includes/media.php';
			}
			if (! function_exists('download_url')) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			if (! function_exists('wp_read_image_metadata')) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}

			if (! is_array($urls)) {
				$urls = array($urls);
			}

			// Get the base uploads directory so that we can skip things in there
			$dir = wp_get_upload_dir();
			$dir = $dir['baseurl'];

			$orig_content = $post['post_content'];
			foreach($urls as $num => $url) {
				// Skip completely if this URL appears to already be local
				if (false !== stristr($url, $dir)) {
					continue;
				}

				$post_data = array(
					'post_author'   => $post['post_author'],
					'post_date'     => $post['post_date'],
					'post_date_gmt' => $post['post_date_gmt'],
					'post_title'    => $post['post_title'],
				);

				// Attempt to download/attach the media to this post
				$id = $this->media_sideload_image($url, $post_id, $post['post_title'], 'id', $post_data);
				if (! is_wp_error($id)) {
					if (0 === $num) {
						// Set the first successfully processed image as Featured
						set_post_thumbnail($post_id, $id);
					}

					// Update the post to reference the new local image
					$data = wp_get_attachment_image_src($id, $size);
					if ($data) {
						$img = '<img src="' . esc_url($data[0]) . '" width="' . esc_attr($data[1]) . '" height="' . esc_attr($data[2]) . '" alt="' . esc_attr($post['post_title']) . '" class="keyring-img" />';
					}

					// Regex out the previous img tag, put this one in there instead, or prepend it to the top/bottom, depending on $append
					if (stristr($post['post_content'], $url)) { // always do this if the image is in there already
						$post['post_content'] = preg_replace('!<img\s[^>]*src=[\'"]' . preg_quote($url) . '[\'"][^>]*>!', $img, $post['post_content']) . "\n";
					} else if ('append' == $where) {
						$post['post_content'] = $post['post_content'] . "\n\n" .  $img;
					} else if ('prepend' == $where) {
						$post['post_content'] = $img . "\n\n" . $post['post_content'];
					}
				}
			}

			// Update and we're out
			if ($post['post_content'] !== $orig_content) {
				$post['ID'] = $post_id;
				return wp_update_post($post);
			}

			return true;
		}

		/**
		 * Similar to sideload_media, but a little simpler. This will download a video
		 * from a URL, and then embed it into a post by replacing the same URL
		 * 
		 * @author beaulebens <https://github.com/beaulebens>
		 * @author wayubi <https://github.com/wayubi>
		 */
		public function sideload_video($urls, $post_id) {
			$this->log(__METHOD__);

			if (! function_exists('media_sideload_image')) {
				require_once ABSPATH . 'wp-admin/includes/media.php';
			}
			if (! function_exists('download_url')) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			if (! function_exists('wp_read_image_metadata')) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}

			if (! is_array($urls)) {
				$urls = array($urls);
			}

			foreach($urls as $url) {
				$file = array();
				$file['tmp_name'] = download_url($url);
				$file['name']     = basename(explode('?', $url)[0]); // Strip any querystring to avoid confusing mimetypes

				if (is_wp_error($file['tmp_name'])) {
					// Download failed, leave the post alone
					@unlink($file_array['tmp_name']);
				} else {

					$post_data = get_post($post_id);
					$post_data = array(
						'post_author'   => $post_data->post_author,
						'post_date'     => $post_data->post_date,
						'post_date_gmt' => $post_data->post_date_gmt,
						'post_title'    => $post_data->post_title,
					);

					// Download worked, now import into Media Library and attach to the specified post
					$id = $this->media_handle_sideload($file, $post_id, null, $post_data);
					@unlink($file_array['tmp_name']);
					if (! is_wp_error($id)) {
						// Update URL in post to point to the local copy
						$post_data = get_post($post_id);
						$post_data->post_content = str_replace(esc_url($url), wp_get_attachment_url($id), $post_data->post_content);
						wp_update_post($post_data);
					}
				}
			}
		}

		/**
		 * @author cfinke <https://github.com/cfinke>
		 * @author wayubi <https://github.com/wayubi>
		 */
		private function sideload_photo_to_album($photo, $album_id) {
			$this->log(__METHOD__);

			global $wpdb;
			
			$photo_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'facebook_id' AND meta_value = %s", $photo['facebook_id']));

			if (is_null($photo_id)) {
				$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->postmeta} (meta_key, meta_value) VALUES (%s, %s)", 'facebook_id', $photo['facebook_id']));
				$photo_id = $this->sideload_album_photo($photo['src'], $album_id, $photo['post_title'], $photo['post_date'], $photo['post_date_gmt']);

				$wpdb->query($wpdb->prepare("UPDATE {$wpdb->postmeta} SET post_id = %d WHERE meta_key = %s AND meta_value = %s", $photo_id, 'facebook_id', $photo['facebook_id']));
				add_post_meta($photo_id, 'raw_import_data', json_encode($photo['facebook_raw']));
			}

			return $photo_id;
		}

		/**
		 * @author cfinke <https://github.com/cfinke>
		 * @author wayubi <https://github.com/wayubi>
		 */
		private function sideload_album_photo($file, $post_id, $desc = '', $post_date = null, $post_date_gmt = null) {
			$this->log(__METHOD__);

			if (!function_exists('media_handle_sideload'))
				require_once ABSPATH . 'wp-admin/includes/media.php';
			if (!function_exists('download_url'))
				require_once ABSPATH . 'wp-admin/includes/file.php';
			if (!function_exists('wp_read_image_metadata'))
				require_once ABSPATH . 'wp-admin/includes/image.php';

			/* Taken from media_sideload_image. There's probably a better way that doesn't include so much copy/paste. */
			// Download file to temp location
			$tmp = download_url($file);
			// Set variables for storage
			// fix file filename for query strings
			preg_match('/[^\?]+\.(jpe?g|jpe|gif|png)\b/i', $file, $matches);
			$file_array['name'] = basename($matches[0]);
			$file_array['tmp_name'] = $tmp;
			// If error storing temporarily, unlink
			if (is_wp_error($tmp)) {
					@unlink($file_array['tmp_name']);
					$file_array['tmp_name'] = '';
			}

			$post_author  = $this->get_option('author');
			$post_title   = $this->prepare_post_title($desc);
			$post_content = $desc;

			$post_data = compact(
				'post_date',
				'post_date_gmt',
				'post_author',
				'post_title',
				'post_content'
			);

			// do the validation and storage stuff
			$id = $this->media_handle_sideload($file_array, $post_id, $desc, $post_data);
			/* End copy/paste */

			@unlink($file_array['tmp_name']);

			return $id;
		}

		/**
		 * Downloads an image from the specified URL, saves it as an attachment, and optionally attaches it to a post.
		 *
		 * @since 2.6.0
		 * @since 4.2.0 Introduced the `$return` parameter.
		 * @since 4.8.0 Introduced the 'id' option for the `$return` parameter.
		 * @since 5.3.0 The `$post_id` parameter was made optional.
		 * @since 5.4.0 The original URL of the attachment is stored in the `_source_url`
		 *              post meta value.
		 *
		 * @param string $file    The URL of the image to download.
		 * @param int    $post_id Optional. The post ID the media is to be associated with.
		 * @param string $desc    Optional. Description of the image.
		 * @param string $return  Optional. Accepts 'html' (image tag html) or 'src' (URL),
		 *                        or 'id' (attachment ID). Default 'html'.
		 * @param array  $post_data  Optional. Post data to override. Default empty array.
		 * @return string|int|WP_Error Populated HTML img tag, attachment ID, or attachment source
		 *                             on success, WP_Error object otherwise.
		 * 
		 * @author Wordpress </wp-admin/includes/media.php>
		 * @author wayubi <https://github.com/wayubi>
		 */
		private function media_sideload_image($file, $post_id = 0, $desc = null, $return = 'html', $post_data = array()) {
			$this->log(__METHOD__);

			if (! empty($file)) {

				$allowed_extensions = array('jpg', 'jpeg', 'jpe', 'png', 'gif');

				/**
				 * Filters the list of allowed file extensions when sideloading an image from a URL.
				 *
				 * The default allowed extensions are:
				 *
				 *  - `jpg`
				 *  - `jpeg`
				 *  - `jpe`
				 *  - `png`
				 *  - `gif`
				 *
				 * @since 5.6.0
				 *
				 * @param string[] $allowed_extensions Array of allowed file extensions.
				 * @param string   $file               The URL of the image to download.
				 */
				$allowed_extensions = apply_filters('image_sideload_extensions', $allowed_extensions, $file);
				$allowed_extensions = array_map('preg_quote', $allowed_extensions);
		
				// Set variables for storage, fix file filename for query strings.
				preg_match('/[^\?]+\.(' . implode('|', $allowed_extensions) . ')\b/i', $file, $matches);
		
				if (! $matches) {
					return new WP_Error('image_sideload_failed', __('Invalid image URL.'));
				}
		
				$file_array         = array();
				$file_array['name'] = wp_basename($matches[0]);
		
				// Download file to temp location.
				$file_array['tmp_name'] = download_url($file);
		
				// If error storing temporarily, return the error.
				if (is_wp_error($file_array['tmp_name'])) {
					return $file_array['tmp_name'];
				}
		
				// Do the validation and storage stuff.
				$id = $this->media_handle_sideload($file_array, $post_id, $desc, $post_data);
		
				// If error storing permanently, unlink.
				if (is_wp_error($id)) {
					@unlink($file_array['tmp_name']);
					return $id;
				}
		
				// Store the original attachment source in meta.
				add_post_meta($id, '_source_url', $file);
		
				// If attachment ID was requested, return it.
				if ('id' === $return) {
					return $id;
				}
		
				$src = wp_get_attachment_url($id);
			}
		
			// Finally, check to make sure the file has been saved, then return the HTML.
			if (! empty($src)) {
				if ('src' === $return) {
					return $src;
				}
		
				$alt  = isset($desc) ? esc_attr($desc) : '';
				$html = "<img src='$src' alt='$alt' />";
		
				return $html;
			} else {
				return new WP_Error('image_sideload_failed');
			}
		}

		/**
		 * 
		 * Handles a side-loaded file in the same way as an uploaded file is handled by media_handle_upload().
		 *
		 * @since 2.6.0
		 * @since 5.3.0 The `$post_id` parameter was made optional.
		 *
		 * @param string[] $file_array Array that represents a `$_FILES` upload array.
		 * @param int      $post_id    Optional. The post ID the media is associated with.
		 * @param string   $desc       Optional. Description of the side-loaded file. Default null.
		 * @param array    $post_data  Optional. Post data to override. Default empty array.
		 * @return int|WP_Error The ID of the attachment or a WP_Error on failure.
		 * 
		 * @author Wordpress </wp-admin/includes/media.php>
		 * @author wayubi <https://github.com/wayubi>
		 */
		private function media_handle_sideload($file_array, $post_id = 0, $desc = null, $post_data = array()) {
			$this->log(__METHOD__);

			$overrides = array('test_form' => false);

			$time = current_time('mysql');
			$post = get_post($post_id);

			if ($post) {
				if (substr($post->post_date, 0, 4) > 0) {
					$time = $post->post_date;
				}
			}

			$file = wp_handle_sideload($file_array, $overrides, $time);

			if (isset($file['error'])) {
				return new WP_Error('upload_error', $file['error']);
			}

			$url     = $file['url'];
			$type    = $file['type'];
			$file    = $file['file'];
			$title   = preg_replace('/\.[^.]+$/', '', wp_basename($file));
			$content = '';

			// Use image exif/iptc data for title and caption defaults if possible.
			$image_meta = wp_read_image_metadata($file);

			if ($image_meta) {
				if (trim($image_meta['title']) && ! is_numeric(sanitize_title($image_meta['title']))) {
					$title = $image_meta['title'];
				}

				if (trim($image_meta['caption'])) {
					$content = $image_meta['caption'];
				}
			}

			if (isset($desc)) {
				$title = $desc;
			}

			// Construct the attachment array.
			$attachment = array_merge(
				array(
					'post_mime_type' => $type,
					'guid'           => $url,
					'post_parent'    => $post_id,
					'post_title'     => $title,
					'post_content'   => $content,
				),
				$post_data
			);

			// This should never be set as it would then overwrite an existing attachment.
			unset($attachment['ID']);

			// Save the attachment metadata.
			$attachment_id = wp_insert_attachment($attachment, $file, $post_id, true);

			if (! is_wp_error($attachment_id)) {
				wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $file));
			}

			return $attachment_id;
		}

		/**
		 * Cache album images
		 * 
		 * @author wayubi <https://github.com/wayubi>
		 */
		private function cache_album_images() {
			$this->log(__METHOD__);

			$cache_album_images_reset       = (bool) $this->get_option('cache_album_images_reset');

			$cache_album_images_current_run = (int) $this->fetchUtcTimestamp();
			$cache_album_images_first_run   = (int) $this->get_option('cache_album_images_first_run');
			$cache_album_images_last_run    = (int) $this->get_option('cache_album_images_last_run');

			$cache_album_images             = (array) $this->get_option('cache_album_images');

			// Reset cache daily.
			if ($cache_album_images_reset || ($cache_album_images_current_run - $cache_album_images_first_run) >= (strtotime('+1 day') - strtotime('now'))) {
				$cache_album_images_first_run = (int) $cache_album_images_current_run;
				$cache_album_images_last_run  = 0;
				$cache_album_images           = array();
			}

			$albums = $this->service->request('https://graph.facebook.com/' . $this->endpoint_prefix . '/albums?fields=id,name,created_time,updated_time,privacy,type');
			foreach ($albums->data as $album) {

				if ($album->name != 'Timeline Photos') continue;

				$url = 'https://graph.facebook.com/' . $album->id . '/photos?fields=id,name,link,images,created_time,updated_time';
				while ($url = $this->cache_album_images_walk($url, $cache_album_images, $cache_album_images_last_run));
			}

			$cache_album_images_last_run = (int) $cache_album_images_current_run;

			$this->set_option('cache_album_images', $cache_album_images);
			$this->set_option('cache_album_images_first_run', $cache_album_images_first_run);
			$this->set_option('cache_album_images_last_run', $cache_album_images_last_run);

			return $cache_album_images;
		}

		/**
		 * Cache album images subroutine
		 * 
		 * @author wayubi <https://github.com/wayubi>
		 */
		private function cache_album_images_walk($url, &$cache_album_images, $cache_album_images_last_run) {
			$this->log(__METHOD__);
			
			$photos = $this->service->request($url, array('method' => $this->request_method, 'timeout' => 10));
			if (empty($photos) || empty($photos->data)) {
				return false;
			}

			foreach ($photos->data as $photo) {
				if ($cache_album_images_last_run < strtotime($photo->updated_time)) {
					$this->log(__METHOD__ . ': add_to_cache : ' . $photo->id);
					$cache_album_images[$photo->id] = $this->fetchHighResImage($photo->images);
				} else {
					return false;
				}
			}

			if (isset($photos->paging) && !empty($photos->paging->next))
				return $photos->paging->next;

			return false;
		}

		/**
		 * Gets open street map bounding box data.
		 * 
		 * @param float $lat Latitude.
		 * @param float $lon Longitude.
		 * @param int $area Area in meters.
		 * @return array Bounding box data.
		 * 
		 * @author krzysiunet <https://help.openstreetmap.org/users/12638/krzysiunet>
		 * @author wayubi <https://github.com/wayubi>
		 */
		private function getOpenStreetMapBBox($lat, $lon, $area) {
			$offset = $area / 2;
			return [
				0 => $this->getOpenStreetMapCoordOffset(1, $lat, $lon, -$offset),
				1 => $this->getOpenStreetMapCoordOffset(0, $lat, $lon, -$offset),
				2 => $this->getOpenStreetMapCoordOffset(1, $lat, $lon, $offset),
				3 => $this->getOpenStreetMapCoordOffset(0, $lat, $lon, $offset),
				4 => $lat,
				5 => $lon
			]; // 0 = minlon, 1 = minlat, 2 = maxlon, 3 = maxlat, 4,5 = original val (marker)
		}

		/**
		 * Gets coordinates from offset
		 * 
		 * @param int $what What (?).
		 * @param float $lat Latitude.
		 * @param float $lon Longitude.
		 * @param float $offset Area / 2.
		 * @return float Coordinate offset data.
		 * 
		 * @author krzysiunet <https://help.openstreetmap.org/users/12638/krzysiunet>
		 * @author wayubi <https://github.com/wayubi>
		 */
		private function getOpenStreetMapCoordOffset($what, $lat, $lon, $offset) {
			$earthRadius = 6378137;
			$coord = [0 => $lat, 1 => $lon];
			$radOff = $what === 0 ? $offset / $earthRadius : $offset / ($earthRadius * cos(M_PI * $coord[0] / 180));
			return $coord[$what] + $radOff * 180 / M_PI;    
		}

		/**
		 * Wrapper for make_clickable with domain exemptions.
		 * 
		 * @param string $text Text to make clickable.
		 * @param array $domains Domains to exempt where value is the domain.
		 * @return string Text made clickable.
		 * 
		 * @author wayubi <https://github.com/wayubi>
		 */
		private function make_clickable($text, $domains = array()) {
			$this->log(__METHOD__);

			foreach($domains as $domain) {
				$text = preg_replace('/http(s*:\/\/(?:www.)*' . $domain . ')/', 'hxxp${1}', $text);
			}

			$text = str_replace('”', ' ”', $text);
			$text = make_clickable($text);
			$text = str_replace(' ”', '”', $text);
			$text = str_replace('hxxp', 'http', $text);

			return $text;
		}

		/**
		 * Prepare post title.
		 * 
		 * @param string $post_title Post title to prepare.
		 * @return string Prepared post title.
		 * 
		 * @author wayubi <https://github.com/wayubi>
		 */
		private function prepare_post_title($post_title) {
			$this->log(__METHOD__);

			$message = preg_split('/\n/', $post_title);
			$title_words = explode(' ', strip_tags($message[0]));
			$post_title  = implode(' ', array_slice($title_words, 0, 9));

			$post_title = rtrim($post_title, '.,;:');

			if (count($title_words) > 9) {
				if (!in_array(substr($post_title, -1), array('.', '?', '!', ',', ';', ':')))
					$post_title .= '...';
			}

			return $post_title;
		}

		/**
		 * Fetch high res image from images array.
		 * 
		 * @param array $images Images where key is resolution and value is source.
		 * @return string High resolution image.
		 * 
		 * @author wayubi <https://github.com/wayubi>
		 */
		private function fetchHighResImage($images) {
			$this->log(__METHOD__);

			$i = array();
			foreach ($images as $image) {
				$i[$image->height] = $image->source;
			}
			krsort($i);

			return array_shift($i);
		}

		/**
		 * Fetch UTC Timestamp.
		 * 
		 * @return int UTC Timestamp.
		 * 
		 * @author wayubi <https://github.com/wayubi>
		 */
		private function fetchUtcTimestamp() {
			return strtotime(gmdate("M d Y H:i:s", time()));
		}

		/**
		 * Log text to file.
		 * 
		 * @param string $s Text to log.
		 * 
		 * @author wayubi <https://github.com/wayubi>
		 */
		private function log($s) {
			file_put_contents(static::LOG_PATH, '[' . date('Y-m-d H:i:s') . '] ' . $s . PHP_EOL, FILE_APPEND);
		}
	}

} // end function Keyring_Facebook_Importer

/**
 * @author cfinke <https://github.com/cfinke>
 */
add_action('init', function() {
	Keyring_Facebook_Importer(); // Load the class code from above
	keyring_register_importer(
		'facebook',
		'Keyring_Facebook_Importer',
		plugin_basename(__FILE__),
		__('Download all of your Facebook statuses as individual Posts (with a "status" post format).', 'keyring')
	);
});

/**
 * @author cfinke <https://github.com/cfinke>
 */
add_filter('keyring_facebook_scope', function ($scopes) {
	$scopes[] = 'user_posts';
	$scopes[] = 'user_photos';
	// $scopes[] = 'manage_pages';
	return $scopes;
});
