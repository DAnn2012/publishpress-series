<?php
/**
 * This class can be customized to quickly add a review request system.
 *
 * It includes:
 * - Multiple trigger groups which can be ordered by priority.
 * - Multiple triggers per group.
 * - Customizable messaging per trigger.
 * - Link to review page.
 * - Request reviews on a per user basis rather than per site.
 * - Allows each user to dismiss it until later or permanently seamlessly via AJAX.
 * - Integrates with attached tracking server to keep anonymous records of each triggers effectiveness.
 *   - Tracking Server API: https://gist.github.com/danieliser/0d997532e023c46d38e1bdfd50f38801
 *
 * To use this please include the following credit block as well as completing the following TODOS.
 *
 * Original Author: danieliser
 * Original Author URL: https://danieliser.com
 *
 * TODO Search & Replace pp_series_ with your prefix
 * TODO Search & Replace pp_series_ with your prefix
 * TODO Search & Replace 'organize-series' with your 'organize-series'
 * TODO Change the $api_url if your using the accompanying tracking server. Leave it blank to disable this feature.
 * TODO Modify the ::triggers function array with your custom triggers & text.
 * TODO Keep in mind highest priority group/code combination that has all passing conditions will be chosen.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('PP_Series_Modules_Reviews')) {
    /**
     * Class PP_Series_Modules_Reviews
     *
     * This class adds a review request system for your plugin or theme to the WP dashboard.
     */
    class PP_Series_Modules_Reviews
    {

        /**
         * Tracking API Endpoint.
         *
         * @var string
         */
        public static $api_url = '';

        /**
         *
         */
        public static function init()
        {
            add_action('init', [__CLASS__, 'hooks']);
            add_action('wp_ajax_pp_series_review_action', [__CLASS__, 'ajax_handler']);
        }

        /**
         * Hook into relevant WP actions.
         */
        public static function hooks()
        {
            if (is_admin() && current_user_can('edit_posts')) {
                self::installed_on();
                if(is_ppseries_admin_pages()){
                    add_action('admin_notices', [__CLASS__, 'admin_notices']);
                    add_action('network_admin_notices', [__CLASS__, 'admin_notices']);
                    add_action('user_admin_notices', [__CLASS__, 'admin_notices']);
                    //moved assets to footer due to a plugin stripping out assets in admin notice (https://wordpress.org/plugins/disable-admin-notices/)
                    add_action('admin_footer', [__CLASS__, 'admin_footer']);
                }
            }
        }

        /**
         * Get the install date for comparisons. Sets the date to now if none is found.
         *
         * @return false|string
         */
        public static function installed_on()
        {
            $installed_on = get_option('pp_series_reviews_installed_on', false);

            if (!$installed_on) {
                $installed_on = current_time('mysql');
                update_option('pp_series_reviews_installed_on', $installed_on);
            }

            return $installed_on;
        }

        /**
         *
         */
        public static function ajax_handler()
        {
            $args = wp_parse_args($_REQUEST, [
                'group'  => self::get_trigger_group(),
                'code'   => self::get_trigger_code(),
                'pri'    => self::get_current_trigger('pri'),
                'reason' => 'maybe_later',
            ]);

            if (!wp_verify_nonce($_REQUEST['nonce'], 'pp_series_review_action')) {
                wp_send_json_error();
            }

            try {
                $user_id = get_current_user_id();

                $dismissed_triggers                 = self::dismissed_triggers();
                $dismissed_triggers[$args['group']] = $args['pri'];
                update_user_meta($user_id, '_pp_series_reviews_dismissed_triggers', $dismissed_triggers);
                update_user_meta($user_id, '_pp_series_reviews_last_dismissed', current_time('mysql'));

                switch($args['reason']) {
                    case 'maybe_later':
                        update_user_meta($user_id, '_pp_series_reviews_last_dismissed', current_time('mysql'));
                        break;
                    case 'am_now':
                    case 'already_did':
                        self::already_did(true);
                        break;
                }

                wp_send_json_success();

            } catch (Exception $e) {
                wp_send_json_error($e);
            }
        }

        /**
         * @return int|string
         */
        public static function get_trigger_group()
        {
            static $selected;

            if (!isset($selected)) {

                $dismissed_triggers = self::dismissed_triggers();

                $triggers = self::triggers();

                foreach ($triggers as $g => $group) {
                    foreach ($group['triggers'] as $t => $trigger) {
                        if (!in_array(false,
                                $trigger['conditions']) && (empty($dismissed_triggers[$g]) || $dismissed_triggers[$g] < $trigger['pri'])) {
                            $selected = $g;
                            break;
                        }
                    }

                    if (isset($selected)) {
                        break;
                    }
                }
            }

            return $selected;
        }

        /**
         * @return int|string
         */
        public static function get_trigger_code()
        {
            static $selected;

            if (!isset($selected)) {

                $dismissed_triggers = self::dismissed_triggers();

                foreach (self::triggers() as $g => $group) {
                    foreach ($group['triggers'] as $t => $trigger) {
                        if (!in_array(false,
                                $trigger['conditions']) && (empty($dismissed_triggers[$g]) || $dismissed_triggers[$g] < $trigger['pri'])) {
                            $selected = $t;
                            break;
                        }
                    }

                    if (isset($selected)) {
                        break;
                    }
                }
            }

            return $selected;
        }

        /**
         * @param null $key
         *
         * @return bool|mixed|void
         */
        public static function get_current_trigger($key = null)
        {
            $group = self::get_trigger_group();
            $code  = self::get_trigger_code();

            if (!$group || !$code) {
                return false;
            }

            $trigger = self::triggers($group, $code);

            if(empty($key)){
                $return = $trigger;
            }elseif(isset($trigger[$key])){
                 $return = $trigger[$key];
            }else {
               $return = false;
            }

            return $return;
        }

        /**
         * Returns an array of dismissed trigger groups.
         *
         * Array contains the group key and highest priority trigger that has been shown previously for each group.
         *
         * $return = array(
         *   'group1' => 20
         * );
         *
         * @return array|mixed
         */
        public static function dismissed_triggers()
        {
            $user_id = get_current_user_id();

            $dismissed_triggers = get_user_meta($user_id, '_pp_series_reviews_dismissed_triggers', true);

            if (!$dismissed_triggers) {
                $dismissed_triggers = [];
            }

            return $dismissed_triggers;
        }

        /**
         * Returns true if the user has opted to never see this again. Or sets the option.
         *
         * @param bool $set If set this will mark the user as having opted to never see this again.
         *
         * @return bool
         */
        public static function already_did($set = false)
        {
            $user_id = get_current_user_id();

            if ($set) {
                update_user_meta($user_id, '_pp_series_reviews_already_did', true);

                return true;
            }

            return (bool)get_user_meta($user_id, '_pp_series_reviews_already_did', true);
        }

        /**
         * Gets a list of triggers.
         *
         * @param null $group
         * @param null $code
         *
         * @return bool|mixed|void
         */
        public static function triggers($group = null, $code = null)
        {
            static $triggers;

            if (!isset($triggers)) {

                $time_message = __("Hey, you've been using PublishPress Series for %s on your site. We hope the plugin has been useful. Please could you quickly leave a 5-star rating on WordPress.org? It really does help to keep PublishPress Series growing.",
                    'organize-series');

                $triggers = apply_filters('pp_series_reviews_triggers', [
                    'time_installed' => [
                        'triggers' => [
                            'one_week'     => [
                                'message'    => sprintf($time_message, __('1 week', 'organize-series')),
                                'conditions' => [
                                    strtotime(self::installed_on() . ' +1 week') < time(),
                                ],
                                'link'       => 'https://wordpress.org/support/plugin/organize-series/reviews/?rate=5#rate-response',
                                'pri'        => 10,
                            ],
                            'one_month'    => [
                                'message'    => sprintf($time_message, __('1 month', 'organize-series')),
                                'conditions' => [
                                    strtotime(self::installed_on() . ' +1 month') < time(),
                                ],
                                'link'       => 'https://wordpress.org/support/plugin/organize-series/reviews/?rate=5#rate-response',
                                'pri'        => 20,
                            ],
                            'three_months' => [
                                'message'    => sprintf($time_message, __('3 months', 'organize-series')),
                                'conditions' => [
                                    strtotime(self::installed_on() . ' +3 months') < time(),
                                ],
                                'link'       => 'https://wordpress.org/support/plugin/organize-series/reviews/?rate=5#rate-response',
                                'pri'        => 30,
                            ],

                        ],
                        'pri'      => 10,
                    ]
                ]);

                // Sort Groups
                uasort($triggers, [__CLASS__, 'rsort_by_priority']);

                // Sort each groups triggers.
                foreach ($triggers as $k => $v) {
                    uasort($triggers[$k]['triggers'], [__CLASS__, 'rsort_by_priority']);
                }
            }

            if (isset($group)) {
                if (!isset($triggers[$group])) {
                    return false;
                }


                if (!isset($code)) {
                    $return = $triggers[$group];
                } elseif (isset($triggers[$group]['triggers'][$code])) {
                    $return = $triggers[$group]['triggers'][$code];
                } else {
                    $return = false;
                }

                return $return;
            }

            return $triggers;
        }

        /**
         * Render admin notices if available.
         */
        public static function admin_notices()
        {

            if (self::hide_notices()) {
                return;
            }
            $tigger = self::get_current_trigger();

            ?>

            <div class="notice notice-success is-dismissible pp-series-notice">

                <p>
                <img src="<?php echo PPSERIES_URL ?>/assets/images/icon-256x256.png" class="logo" alt=""/>
                    <?php echo $tigger['message']; ?>
                </p>
                <p>
                    <a class="button button-primary pp-series-dismiss" target="_blank"
                       href="https://wordpress.org/support/plugin/organize-series/reviews/?rate=5#rate-response"
                       data-reason="am_now">
                        <strong><?php _e('Click here to add your rating for PublishPress Series', 'organize-series'); ?></strong>
                    </a> <a href="#" class="button pp-series-dismiss" data-reason="maybe_later">
                        <?php _e('Maybe later', 'organize-series'); ?>
                    </a> <a href="#" class="button pp-series-dismiss" data-reason="already_did">
                        <?php _e('I already did', 'organize-series'); ?>
                    </a>
                </p>

            </div>

            <?php
        }

        /**
         * Render admin notice assets if available.
         */
        public static function admin_footer()
        {

            if (self::hide_notices()) {
                return;
            }

            $group  = self::get_trigger_group();
            $code   = self::get_trigger_code();
            $pri    = self::get_current_trigger('pri');
            $tigger = self::get_current_trigger();

            // Used to anonymously distinguish unique site+user combinations in terms of effectiveness of each trigger.
            $uuid = wp_hash(home_url() . '-' . get_current_user_id());

            ?>

            <script type="text/javascript">
                (function($) {
                    var trigger = {
                        group: '<?php echo $group; ?>',
                        code: '<?php echo $code; ?>',
                        pri: '<?php echo $pri; ?>'
                    }

                    function dismiss(reason) {
                        $.ajax({
                            method: "POST",
                            dataType: "json",
                            url: ajaxurl,
                            data: {
                                action: 'pp_series_review_action',
                                nonce: '<?php echo wp_create_nonce('pp_series_review_action'); ?>',
                                group: trigger.group,
                                code: trigger.code,
                                pri: trigger.pri,
                                reason: reason
                            }
                        })

                        <?php if ( !empty(self::$api_url) ) : ?>
                        $.ajax({
                            method: "POST",
                            dataType: "json",
                            url: '<?php echo self::$api_url; ?>',
                            data: {
                                trigger_group: trigger.group,
                                trigger_code: trigger.code,
                                reason: reason,
                                uuid: '<?php echo $uuid; ?>'
                            }
                        })
                        <?php endif; ?>
                    }

                    $(document)
                        .on('click', '.pp-series-notice .pp-series-dismiss', function(event) {
                            var $this = $(this),
                                reason = $this.data('reason'),
                                notice = $this.parents('.pp-series-notice')

                            notice.fadeTo(100, 0, function() {
                                notice.slideUp(100, function() {
                                    notice.remove()
                                })
                            })

                            dismiss(reason)
                        })
                        .ready(function() {
                            setTimeout(function() {
                                $('.pp-series-notice button.notice-dismiss').click(function(event) {
                                    dismiss('maybe_later')
                                })
                            }, 1000)
                        })
                }(jQuery))
            </script>
            <style>
            .pp-series-notice p {
				margin-bottom: 0;
			}

			.pp-series-notice img.logo {
				float: right;
				margin-left: 10px;
				width: 75px;
				padding: 0.25em;
				border: 1px solid #ccc;
			}

             .button-primary.pp-series-dismiss {
                 margin-bottom: 0 !important;
             }
            </style>
            <?php
        }

        /**
         * Checks if notices should be shown.
         *
         * @return bool
         */
        public static function hide_notices()
        {
            $conditions = [
                self::already_did(),
                self::last_dismissed() && strtotime(self::last_dismissed() . ' +2 weeks') > time(),
                empty(self::get_trigger_code()),
            ];

            return in_array(true, $conditions);
        }

        /**
         * Gets the last dismissed date.
         *
         * @return false|string
         */
        public static function last_dismissed()
        {
            $user_id = get_current_user_id();

            return get_user_meta($user_id, '_pp_series_reviews_last_dismissed', true);
        }

        /**
         * Sort array by priority value
         *
         * @param $a
         * @param $b
         *
         * @return int
         */
        public static function sort_by_priority($a, $b)
        {
            if (!isset($a['pri']) || !isset($b['pri']) || $a['pri'] === $b['pri']) {
                return 0;
            }

            return ($a['pri'] < $b['pri']) ? -1 : 1;
        }

        /**
         * Sort array in reverse by priority value
         *
         * @param $a
         * @param $b
         *
         * @return int
         */
        public static function rsort_by_priority($a, $b)
        {
            if (!isset($a['pri']) || !isset($b['pri']) || $a['pri'] === $b['pri']) {
                return 0;
            }

            return ($a['pri'] < $b['pri']) ? 1 : -1;
        }

    }
}

PP_Series_Modules_Reviews::init();
