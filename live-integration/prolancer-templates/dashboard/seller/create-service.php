<?php 
global $prolancer_opt;

// Initialize all variables with default values
$prolancer_packages = !empty($prolancer_opt['prolancer_packages']) ? $prolancer_opt['prolancer_packages'] : '';
$prolancer_package_feature = !empty($prolancer_opt['prolancer_package_feature']) ? $prolancer_opt['prolancer_package_feature'] : array();
$prolancer_service_featuring_fee = !empty($prolancer_opt['prolancer_service_featuring_fee']) ? $prolancer_opt['prolancer_service_featuring_fee'] : '';

$service_id = 0;
$service = null;
$ids = array();
$attachments = array();
$packages = array();
$faqs = array();
$additional_services = array();
$package_delivery_time = null;

if (!empty($_GET['service_id'])) {
    $service_id = $_GET['service_id'];
}

if ($service_id && get_post_type($service_id) == 'services') {
    $service = get_post($service_id);
} ?>

<div class="white-padding mb-4">
    <h2 class="mb-0"><?php echo esc_html__('Create a Service', 'prolancer'); ?></h2>
</div>

<?php
if (!empty($service)) {
    update_post_meta($service_id, 'service_categories', 'ecommerce');
    $packages = json_decode(get_post_meta($service_id, 'packages', true), true) ?: array();
    $faqs = json_decode(stripslashes(get_post_meta($service_id, 'service_faqs', true)), true) ?: array();
    $additional_services = json_decode(stripslashes(get_post_meta($service_id, 'additional_services', true)), true) ?: array();
    $attachments = get_post_meta($service_id, 'service_attachments', false) ?: array();

    if ($packages) {
        foreach ($packages as $key => $package) {
            $features = isset($package['features']) ? $package['features'] : array();
        }
    }

    if ($attachments) {
        foreach ($attachments as $attachment) {
            if ($attachment) {
                $ids = array_keys($attachment);
            }       
        }
    }
} ?>

<!-- Rest of your code remains exactly the same -->
<div class="white-padding">
    <form id="create-service-form" enctype="multipart/form-data">
        <div class="row">
            <?php pcu_wizard_nav(); ?>
            <div class="pcu-step col-12 col-lg-9" data-step="1" data-title="About service">
              <div class="row">
            <div class="col-md-6 mb-4">
                <input type="text" name='title' class="form-control" value="<?php echo !empty($service) ? esc_attr($service->post_title) : ''; ?>" placeholder="<?php echo esc_attr__('Service Title','prolancer'); ?>">
            </div>
            <div class="col-md-6 mb-4">
                <select name="service_category" class="form-select">
                    <option value=""><?php echo esc_html__('Category','prolancer'); ?></option>
                    <?php prolancer_get_option_list('service-categories', 'service_category', $service_id); ?>
                </select>
            </div>      
            <div class="col-md-6 mb-4">
                <select name="service_english_level" class="form-select">
                    <option value=""><?php echo esc_html__('English Level','prolancer'); ?></option>
                    <?php prolancer_get_option_list('service-english-level', 'service_english_level', $service_id); ?>
                </select>
            </div>
            <div class="col-md-6 mb-4">
                <select name="service_locations" class="form-select">
                    <option value=""><?php echo esc_html__('Locations','prolancer'); ?></option>
                    <?php prolancer_get_option_list('service-locations', 'service_locations', $service_id); ?>
                </select>               
            </div>
            <div class="col-md-12 mb-5">
                <label><?php echo esc_html__('Description','prolancer'); ?></label>
                <textarea name="description" cols="30" rows="10" class="form-control"><?php echo !empty($service) ? esc_html($service->post_content) : ''; ?></textarea>
            </div>
            </div>
            </div>

            <div class="pcu-step col-12 col-lg-9" data-step="2" data-title="Media" hidden>
              <div class="row"><div class="col-md-12 mb-4">
                <label><?php echo esc_html__('Featured Images','prolancer'); ?></label>
                <input id="upload-service-attachments" type="file" class="form-control" multiple accept="image/pdf/doc/docx/ppt/pptx*" data-service-id="<?php echo esc_attr($service_id); ?>" data-nonce="<?php echo wp_create_nonce('upload_file_nonce'); ?>">
                <input type="hidden" name="attachments" class="attachment-ids" value="<?php if (!empty($ids)) { echo json_encode($ids); } ?>">
                <div class="append-images">
                    <?php 
                    if (!empty($attachments) && !empty($ids)) {
                        foreach ($ids as $id) { ?>
                            <div>
                                <img src="<?php echo esc_url(wp_get_attachment_image_src($id, 'prolancer-100x80', true)[0]); ?>">
                                <div>
                                    <h6><?php echo esc_html(get_the_title($id)); ?></h6>
                                    <span><?php echo 'File size:'.filesize(get_attached_file($id)).' KB'; ?></span>
                                    <a class="delete-attachment" data-service-id="<?php echo esc_attr($service_id); ?>" data-nonce="<?php echo wp_create_nonce('delete_attachment_nonce'); ?>" data-attachment-id="<?php echo esc_attr($id); ?>" href="#"><i class="fas fa-trash-alt"></i></a>
                                </div>
                            </div>                      
                        <?php }
                    } ?>                
                </div>
            </div>
            
            </div>
            </div>

            <div class="pcu-step col-12 col-lg-9" data-step="3" data-title="Pricing" hidden>
              <div class="row"><!-- The packages section remains exactly as you had it -->
            <div class="col-md-12 mb-4">
                <h4 class="mb-4"><?php echo esc_html__( "Packages", 'prolancer' ); ?></h4>              
                <div class="packages">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th scope="col"></th>
                                    <?php for ($i=0; $i < $prolancer_packages; $i++) { ?>
                                        <th scope="col"><?php echo esc_html__( 'Package ', 'prolancer' ).$i ?></th>
                                    <?php } ?>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><?php echo esc_html__( 'Name', 'prolancer' ) ?></td>
                                    <?php for ($i=0; $i < $prolancer_packages; $i++) { ?>
                                        <td><input type="text" name="package_name[]" placeholder="<?php echo esc_attr__('Name your package','prolancer'); ?>" value="<?php if(!empty($packages[$i]['name'])){echo esc_attr($packages[$i]['name']);} ?>"></td>
                                    <?php } ?>
                                </tr>
                                <tr>
                                    <td><?php echo esc_html__( 'Description', 'prolancer' ) ?></td>
                                    <?php for ($i=0; $i < $prolancer_packages; $i++) { ?>
                                        <td><textarea name="package_description[]" placeholder="<?php echo esc_attr__('Describe the details of your offering','prolancer'); ?>"><?php if(!empty($packages[$i]['description'])){echo esc_html($packages[$i]['description']);} ?></textarea></td>
                                    <?php } ?>
                                </tr>
                                <tr>
                                    <td><?php echo esc_html__( 'Delivery Time', 'prolancer' ) ?></td>
                                    <?php for ($i=0; $i < $prolancer_packages; $i++) { ?>
                                        <td>
                                            <select name="package_delivery_time[]" class="form-select">
                                                <option value=""><?php echo esc_html__( 'Delivery Time', 'prolancer' ); ?></option>
                                                <?php                                               
                                                if(!empty($packages[$i]['delivery_time'])){
                                                    $package_delivery_time = get_term_by( 'id', $packages[$i]['delivery_time'], 'delivery-time' );
                                                }

                                                $delivery_times = get_terms(array(
                                                    'taxonomy' => 'delivery-time',
                                                    'hide_empty' => false
                                                ));
                                                
                                                foreach($delivery_times as $delivery_time){ ?>
                                                    <option value="<?php echo esc_attr($delivery_time->term_id); ?>" <?php if(!empty($package_delivery_time)){ selected( $package_delivery_time->term_id, $delivery_time->term_id );} ?>><?php echo esc_html($delivery_time->name); ?></option>
                                                <?php } ?>
                                            </select>
                                        </td>
                                    <?php } ?>
                                </tr>
                                <tr>
                                    <td><?php echo esc_html__( 'Revisions', 'prolancer' ) ?></td>
                                    <?php for ($i=0; $i < $prolancer_packages; $i++) { ?>
                                        <td><input type="text" inputmode="decimal" data-num="1" name="package_revision[]" placeholder="<?php echo esc_attr('3') ?>" value="<?php if(!empty($packages[$i]['revision'])){echo esc_attr($packages[$i]['revision']);} ?>"></td>
                                    <?php } ?>
                                </tr>
                                <?php if ($prolancer_package_feature) {
                                    foreach ($prolancer_package_feature as $feature){ ?>
                                    <tr>
                                        <td><?php echo esc_html( ucwords(str_replace('packagefeature', '', str_replace('_', ' ', $feature)))) ?></td>
                                        <?php for ($i=0; $i < $prolancer_packages; $i++) {

                                            $feature_value = isset($package['features']['packagefeature_'.str_replace(' ', '_', strtolower($feature))][$i]) ? $package['features']['packagefeature_'.str_replace(' ', '_', strtolower($feature))][$i] : '';

                                            ?>
                                            <td class="text-center">
                                                <input type="checkbox" class="form-check-input" <?php if ($feature_value == 'yes'){echo "checked";} ?>>
                                                <input type="hidden" name="<?php echo esc_attr('packagefeature_'.str_replace(' ', '_', strtolower($feature))) ?>[]" value="<?php if ($feature_value == 'yes'){echo "yes";}else{echo "no";} ?>">
                                            </td>
                                        <?php } ?>
                                    </tr>
                                <?php }
                                } ?>
                                <tr>
                                    <td><?php echo esc_html__( 'Price', 'prolancer' ) ?></td>
                                    <?php for ($i=0; $i < $prolancer_packages; $i++) { ?>
                                        <td><input type="text" inputmode="decimal" data-num="1" name="package_price[]" placeholder="<?php echo esc_attr( get_woocommerce_currency_symbol()); ?>" value="<?php if(!empty($packages[$i]['price'])){echo esc_attr($packages[$i]['price']);} ?>"></td>
                                    <?php } ?>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="col-md-12 mb-5">
                <h4 class="mb-4"><?php echo esc_html__( "Add Additional Service", 'prolancer' ); ?></h4>                
                <div class="additional-services sortable">
                    <?php 
                    if(!empty($additional_services)){
                        foreach($additional_services as $i => $additional_service){ ?>
                        <div class="row mb-4">
                            <div class="col-sm-1">
                                <i class="fa fa-bars"></i>
                            </div>
                            <div class="col-sm-10 my-auto">
                                <input type="text" name='additional_service_title[]' class="form-control" value="<?php echo esc_attr($additional_service['title']); ?>" placeholder="<?php echo esc_html__( 'Title', 'prolancer' ); ?>">
                                <textarea name='additional_service_description[]' class="form-control" placeholder="<?php echo esc_html__( 'Description', 'prolancer' ); ?>"><?php echo esc_html($additional_service['description']); ?></textarea>
                                <div class="input-group mb-3">
                                  <span class="input-group-text">$</span>
                                  <input type="text" inputmode="decimal" data-num="1" name='additional_service_price[]' class="form-control mb-0" value="<?php echo esc_attr($additional_service['price']); ?>" placeholder="<?php echo esc_html__( 'Price', 'prolancer' ); ?>">
                                </div>                                                      
                            </div>
                            <div class="col-sm-1">
                              <i class="fas fa-trash"></i>
                            </div>
                        </div>
                        <?php }
                    } ?>
                </div>
                <a href="#" class="add-additional-service prolancer-btn" data-nonce="<?php echo wp_create_nonce('additional_service_nonce'); ?>"><i class="fal fa-plus"></i> <?php echo esc_html__( "Add Extra Service", 'prolancer' ); ?> </a>
            </div>
            </div>
            </div>

            <div class="pcu-step col-12 col-lg-9" data-step="4" data-title="FAQ" hidden>
              <div class="row"><div class="col-md-12 mb-5">
                <h4 class="mb-4"><?php echo esc_html__( "FAQ", 'prolancer' ); ?></h4>               
                <div class="faqs sortable">
                <?php
                if(!empty($faqs)){
                    foreach($faqs as $i => $faq){ ?>
                    <div class="row mb-4">
                        <div class="col-sm-1">
                            <i class="fa fa-bars"></i>
                        </div>
                        <div class="col-sm-10 my-auto">
                            <input type="text" name='faq_title[]' class="form-control" value="<?php echo esc_attr($faq['title']); ?>" placeholder="<?php echo esc_html__( 'Title', 'prolancer' ); ?>">
                          <textarea name='faq_description[]' class="form-control" placeholder="<?php echo esc_html__( 'Description', 'prolancer' ); ?>"><?php echo esc_html($faq['description']); ?></textarea>
                        </div>
                        <div class="col-sm-1">
                          <i class="fas fa-trash"></i>
                        </div>
                    </div>
                    <?php }
                } ?>
            </div>
            <a href="#" class="add-new-faq prolancer-btn"><i class="fal fa-plus"></i> <?php echo esc_html__( "Add New FAQ", 'prolancer' ); ?> </a>
            </div>          
            <?php if ($service_id && get_post_meta($service_id, 'featured_service', true) != true ){ ?>
                <div class="col-md-12 mb-4">
                    <?php if (get_post_meta($service_id, 'featured_service', true) == true ){ ?>
                        <input type="hidden" name="service_update" value="true">
                    <?php } ?>
                    <label class="form-check-label" for="featured"><?php echo esc_html__('Make the service Featured','prolancer'); ?></label>
                    <input class="form-check-input" name="featured_service" id="featured" type="checkbox" <?php if(get_post_meta($service_id, 'featured_service', true) == true){echo'checked';} ?>>
                    <i class="d-block">
                        <?php echo esc_html__('If this service is approved for featuring will charge you ','prolancer'); ?>
                        <?php if (class_exists( 'WooCommerce' )) {
                            echo wc_price($prolancer_service_featuring_fee);
                        } ?>
                    </i>
                </div>
            <?php } ?>
            <div class="col-md-12">
                <a href="#" id="create-service" class="prolancer-btn" data-service-id="<?php echo esc_attr($service_id); ?>" data-nonce="<?php echo wp_create_nonce('create_service_nonce'); ?>"><?php if (!empty($_GET['service_id'])) {
                    echo esc_html__( 'Update service', 'prolancer' );
                } else {
                    echo esc_html__( 'Create service', 'prolancer' );
                } ?></a>
            </div>
        </div>
            </div>

            <?php pcu_wizard_controls(); ?>
</div>
    </form>
</div>