<?php 
// Initialize variables
$result = [];
$proposal_id = isset($_GET['proposal_id']) ? (int)$_GET['proposal_id'] : 0;

if (is_user_logged_in() && $proposal_id) {
    $buyer_id = (int)get_user_meta(get_current_user_id(), 'buyer_id', true);
    global $wpdb;
    $table = 'prolancer_project_proposals';
    
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) == $table) {
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} 
                WHERE `id` = %d 
                AND `buyer_id` = %d 
                AND `status` = 'complete' 
                ORDER BY timestamp DESC 
                LIMIT 1",
                $proposal_id,
                $buyer_id
            ),
            ARRAY_A
        );
    }
} ?>

<div class="white-padding mb-4">
  <h2 class="mb-0"><?php echo esc_html__('Completed Projects Details', 'prolancer'); ?></h2>
</div>

<?php if (!empty($result)) { ?>
  <div class="table-responsive">
    <table class="prolancer-table">
      <thead>
        <tr>
          <th scope="col"><?php echo esc_html__('Hired Seller', 'prolancer'); ?></th>
          <th scope="col"><?php echo esc_html__('Project', 'prolancer'); ?></th>
          <th scope="col"><?php echo esc_html__('Day to complete', 'prolancer'); ?></th>
          <th scope="col"><?php echo esc_html__('Price', 'prolancer'); ?></th>
          <th scope="col"><?php echo esc_html__('Proposed Price', 'prolancer'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php
        $project_id = (int)$result['project_id'];
        $seller_id = (int)$result['seller_id'];
        $project = get_post($project_id);
        $seller_author_id = get_post_field('post_author', $seller_id);
        $seller_profile_attachment = get_post_meta($seller_id, 'seller_profile_attachment', true);
        $seller_image = $seller_profile_attachment ? wp_get_attachment_image(prolancer_get_image_id($seller_profile_attachment), [50, 50], false) : '';
        $day_term = get_term_by('id', (int)$result['day_to_complete'], 'project-duration');
        $price = get_post_meta($project_id, 'project_price', true);
        $proposed_price = $result['proposed_price'];
        ?>
        <tr>
          <td>
            <a href="<?php echo esc_url(get_the_permalink($seller_id)); ?>" target="_blank">
              <?php if (!empty($seller_image)) {
                  echo wp_kses($seller_image, [
                      "img" => [
                          "src" => [],
                          "alt" => [],
                          "style" => [],
                          "class" => [],
                          "width" => [],
                          "height" => []
                      ]
                  ]);
              } else {
                  echo get_avatar($seller_author_id, 50);
              } ?>
            </a>
          </td>
          <td>
            <a href="<?php echo esc_url(get_the_permalink($project_id)); ?>" target="_blank">
              <?php echo esc_html($project->post_title); ?>
            </a>
          </td>
          <td><?php echo $day_term ? esc_html($day_term->name) : ''; ?></td>
          <td>
            <?php if (class_exists('WooCommerce') && $price) {
                echo wc_price($price);
            } ?>
          </td>
          <td>
            <?php if (class_exists('WooCommerce') && isset($proposed_price)) {
                echo wc_price($proposed_price);
            } ?>
          </td>
        </tr>
      </tbody>
    </table>
  </div>

  <?php 
  $table = 'prolancer_project_messages';
  $messages = [];

  if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) == $table) {
      $messages = $wpdb->get_results(
          $wpdb->prepare(
              "SELECT * FROM {$table} WHERE `proposal_id` = %d",
              $proposal_id
          )
      ) ?: [];
  }

  if (!empty($messages)) { ?>
    <div class="chat-box white-padding mt-4">
      <?php
      $message_sender = (int)get_user_meta(get_current_user_id(), 'buyer_id', true);
      
      foreach ($messages as $message) {
          $sender_id = (int)$message->sender_id;
          $is_sender = ($sender_id == $message_sender);
          $sender_profile = $is_sender ? 'buyer' : 'seller';
          $profile_name = get_post_meta($sender_id, $sender_profile.'_profile_name', true);
          $profile_attachment = get_post_meta($sender_id, $sender_profile.'_profile_attachment', true);
          $sender_image = $profile_attachment ? wp_get_attachment_image(prolancer_get_image_id($profile_attachment), [50, 50], false) : '';
          $sender_author_id = get_post_field('post_author', $sender_id);
      ?>
      <div class="chat-list <?php echo $is_sender ? 'message_sender' : 'message_receiver'; ?>">
        <div class="row">
          <?php if ($is_sender) { ?>
            <div class="col-9">
              <p><?php echo esc_html($message->message); ?></p>
              <?php pcu_render_attachments( $message->attachment_id ); ?>
            </div>
            <div class="col-3">
              <a href="<?php echo esc_url(get_the_permalink($sender_id)); ?>" target="_blank">
                <?php echo esc_html($profile_name); ?>
                <?php if (!empty($sender_image)) {
                    echo wp_kses($sender_image, [
                        "img" => [
                            "src" => [],
                            "alt" => [],
                            "style" => [],
                            "class" => [],
                            "width" => [],
                            "height" => []
                        ]
                    ]);
                } else {
                    echo get_avatar($sender_author_id, 50);
                } ?>
              </a>
            </div>
          <?php } else { ?>
            <div class="col-3">
              <a href="<?php echo esc_url(get_the_permalink($sender_id)); ?>" target="_blank">
                <?php if (!empty($sender_image)) {
                    echo wp_kses($sender_image, [
                        "img" => [
                            "src" => [],
                            "alt" => [],
                            "style" => [],
                            "class" => [],
                            "width" => [],
                            "height" => []
                        ]
                    ]);
                } else {
                    echo get_avatar($sender_author_id, 50);
                } ?>
                <?php echo esc_html($profile_name); ?>
              </a>
            </div>
            <div class="col-9">
              <p><?php echo esc_html($message->message); ?></p>
              <?php pcu_render_attachments( $message->attachment_id ); ?>
            </div>
          <?php } ?>
        </div>
      </div>
      <?php } ?>
    </div>
  <?php } else { ?>
    <div class="white-padding mt-4 mb-4">
      <p><?php echo esc_html__('No Message found!', 'prolancer'); ?></p>
    </div>
  <?php } ?>
<?php } else { ?>
  <div class="white-padding">
    <p class="mb-0"><?php echo esc_html__('No result found!', 'prolancer'); ?></p>
  </div>
<?php } ?>