<?php
/* ============================================================
   ECCD FORM 2 - CHILDREN'S PROFILE  (full copy of the official form)
   Used by cdw/add_child.php and cdw/edit_child.php.
   Needs includes/eccd_form_helpers.php.

   Everything is saved in the existing columns of `children`, so
   the other pages (child_profile, reports, ...) keep working.
============================================================ */

require_once __DIR__ . '/eccd_form_helpers.php';

/* ------------------------------------------------------------
   OPTION LISTS (exact wording of the official Form 2)
------------------------------------------------------------ */
function eccd_f2_sex_options()          { return array('Male', 'Female'); }
function eccd_f2_yes_no()               { return array('Yes', 'No'); }
function eccd_f2_born_at_options()      { return array('Hospital', 'Health Center', 'Home'); }
function eccd_f2_tongue_options()       { return array('Tagalog', 'Visayan', 'Ilocano', 'Bicolnon', 'Others'); }
function eccd_f2_vaccine_answers()      { return array('Yes', 'No', "Don't Know"); }
function eccd_f2_frequency_options()    { return array('Always', 'Sometimes', 'Rarely', 'Never'); }
function eccd_f2_meal_options()         { return array('Always', 'Most of the time', 'Sometimes', 'Rarely', 'Never'); }
function eccd_f2_baon_options()         { return array('Money', 'Food', 'Both', 'None', "Don't Know"); }
function eccd_f2_travel_modes()         { return array('Walking', 'Private Vehicle', 'Public Transportation'); }

function eccd_f2_vaccines() {
    return array(
        'vaccine_bcg'     => 'BCG',
        'vaccine_dpt'     => 'DPT',
        'vaccine_opv'     => 'Oral Polio',
        'vaccine_hepab'   => 'Hepa B',
        'vaccine_measles' => 'Measles',
    );
}

function eccd_f2_deformities() {
    return array(
        'deformity_hare_lip'          => 'Hare Lip',
        'deformity_cross_eyed'        => 'Cross-Eyed (Duling or Banlag)',
        'deformity_deaf'              => 'Deaf',
        'deformity_blind'             => 'Blind',
        'deformity_disabled_leg'      => 'Disabled leg',
        'deformity_disabled_arm_hand' => 'Disabled Arm/Hand',
        'deformity_fingers_toes'      => 'Deformity in Fingers/Toes',
    );
}

function eccd_f2_problems() {
    return array(
        'problem_behavior' => 'Behavior',
        'problem_speaking' => 'Speaking',
        'problem_hearing'  => 'Hearing',
        'problem_vision'   => 'Vision',
    );
}

function eccd_f2_learns_options()    { return array('Nobody', 'Siblings', 'Househelp/Maid', 'Tutor', 'Mother/Father/Both', 'Relatives', 'Others'); }
function eccd_f2_food_options()      { return array('Vegetable', 'Pork', 'Chicken', 'Beef', 'Fish', 'Rice', 'Noodle Soup', 'Bread', 'Fruits', 'Cereals', 'Fruit Juice', 'Milk'); }
function eccd_f2_transport_options() { return array('School bus', 'Jeep', 'Bus', 'Banca', 'Calesa', 'Tricycle', 'Pedicab', 'Habal-Habal', 'Others'); }
function eccd_f2_goes_with_options() { return array('Mother', 'Father', 'Both Parents', 'Siblings', 'Relatives', 'Grandparents', 'Maid', 'None'); }

/* column => mysqli bind type, for every Form 2 column saved on `children` */
function eccd_f2_columns() {
    $cols = array(
        'birth_order' => 'i', 'born_at' => 's', 'is_registered' => 's',
        'first_language' => 's', 'second_language' => 's',
        'has_eccd_card' => 'i', 'has_mother_child_book' => 'i', 'has_other_health_record' => 's',
    );
    foreach (eccd_f2_vaccines() as $k => $_) { $cols[$k] = 's'; }
    $cols['vaccine_others_name'] = 's';
    $cols['vaccine_others_status'] = 's';
    foreach (eccd_f2_deformities() as $k => $_) { $cols[$k] = 'i'; }
    foreach (eccd_f2_problems() as $k => $_) { $cols[$k] = 'i'; }
    $cols['is_left_handed'] = 's';
    $cols['siblings_info'] = 's';
    $cols['eccd_experience_info'] = 's';
    $cols['learns_at_home_with'] = 's';
    $cols['plays_with_older_siblings'] = 's';
    $cols['plays_with_younger_siblings'] = 's';
    $cols['plays_with_neighbors'] = 's';
    $cols['has_meal_before_school'] = 's';
    $cols['food_normally_eaten'] = 's';
    $cols['has_baon'] = 's';
    $cols['travel_time_to_dcc_minutes'] = 'i';
    $cols['travel_mode_to_dcc'] = 's';
    $cols['travel_time_to_ncdc_minutes'] = 'i';
    $cols['travel_mode_to_ncdc'] = 's';
    $cols['public_transport_type'] = 's';
    $cols['goes_to_school_with'] = 's';
    return $cols;
}

/* ------------------------------------------------------------
   COLLECT  (read + validate what the form posted)
------------------------------------------------------------ */
function eccd_f2_pick($value, $allowed) {
    $value = trim((string) $value);
    return in_array($value, $allowed, true) ? $value : null;
}

function eccd_f2_pick_many($values, $allowed) {
    if (!is_array($values)) {
        return null;
    }
    $out = array();
    foreach ($allowed as $opt) {
        if (in_array($opt, $values, true)) {
            $out[] = $opt;
        }
    }
    return empty($out) ? null : implode(', ', $out);
}

function eccd_f2_text($value, $max = 255) {
    $value = trim(preg_replace('/\s+/', ' ', (string) $value));
    if ($value === '') {
        return null;
    }
    return eccd_substr($value, $max);
}

function eccd_f2_int($value, $min = 0, $max = 100000) {
    $value = trim((string) $value);
    if ($value === '' || !ctype_digit($value)) {
        return null;
    }
    $n = (int) $value;
    return ($n < $min || $n > $max) ? null : $n;
}

/* Returns [column => value] for all columns in eccd_f2_columns() */
function eccd_f2_collect($post, &$errors) {
    $d = array();

    $d['birth_order']   = eccd_f2_int($post['birth_order'] ?? '', 1, 99);
    $d['born_at']       = eccd_f2_pick($post['born_at'] ?? '', eccd_f2_born_at_options());
    $d['is_registered'] = eccd_f2_pick($post['is_registered'] ?? '', eccd_f2_yes_no());

    // 6. Mother Tongue  (Others -> the specified language is stored)
    $tongue = eccd_f2_pick($post['first_language'] ?? '', eccd_f2_tongue_options());
    if ($tongue === 'Others') {
        $other = eccd_f2_text($post['first_language_other'] ?? '', 50);
        $tongue = ($other !== null) ? $other : 'Others';
    }
    $d['first_language'] = $tongue;

    // 7. Other dialects spoken at home
    $d['second_language'] = eccd_f2_text($post['second_language'] ?? '', 50);

    // 10. Does the child have
    $d['has_eccd_card']          = isset($post['has_eccd_card']) ? 1 : 0;
    $d['has_mother_child_book']  = isset($post['has_mother_child_book']) ? 1 : 0;
    $d['has_other_health_record'] = eccd_f2_text($post['has_other_health_record'] ?? '', 150);

    // 11. Vaccination
    foreach (eccd_f2_vaccines() as $field => $_) {
        $d[$field] = eccd_f2_pick($post[$field] ?? '', eccd_f2_vaccine_answers());
    }
    $d['vaccine_others_name']   = eccd_f2_text($post['vaccine_others_name'] ?? '', 100);
    $d['vaccine_others_status'] = eccd_f2_pick($post['vaccine_others_status'] ?? '', eccd_f2_vaccine_answers());

    // 12. Physical attributes
    foreach (eccd_f2_deformities() as $field => $_) { $d[$field] = isset($post[$field]) ? 1 : 0; }
    foreach (eccd_f2_problems() as $field => $_)    { $d[$field] = isset($post[$field]) ? 1 : 0; }
    $d['is_left_handed'] = eccd_f2_pick($post['is_left_handed'] ?? '', eccd_f2_yes_no());

    // 13 / 14
    $d['siblings_info']        = eccd_collect_siblings($post, $errors);
    $d['eccd_experience_info'] = eccd_collect_ece($post);

    // 15. Other performance related inputs
    $d['learns_at_home_with']        = eccd_f2_pick_many($post['learns_at_home_with'] ?? array(), eccd_f2_learns_options());
    $d['plays_with_older_siblings']   = eccd_f2_pick($post['plays_with_older_siblings'] ?? '', eccd_f2_frequency_options());
    $d['plays_with_younger_siblings'] = eccd_f2_pick($post['plays_with_younger_siblings'] ?? '', eccd_f2_frequency_options());
    $d['plays_with_neighbors']        = eccd_f2_pick($post['plays_with_neighbors'] ?? '', eccd_f2_frequency_options());

    // 16. Logistics
    $d['has_meal_before_school'] = eccd_f2_pick($post['has_meal_before_school'] ?? '', eccd_f2_meal_options());
    $d['food_normally_eaten']    = eccd_f2_pick_many($post['food_normally_eaten'] ?? array(), eccd_f2_food_options());
    $d['has_baon']               = eccd_f2_pick($post['has_baon'] ?? '', eccd_f2_baon_options());

    $d['travel_time_to_dcc_minutes']  = eccd_f2_int($post['travel_time_to_dcc_minutes'] ?? '', 0, 1440);
    $d['travel_mode_to_dcc']          = eccd_f2_pick($post['travel_mode_to_dcc'] ?? '', eccd_f2_travel_modes());
    $d['travel_time_to_ncdc_minutes'] = eccd_f2_int($post['travel_time_to_ncdc_minutes'] ?? '', 0, 1440);
    $d['travel_mode_to_ncdc']         = eccd_f2_pick($post['travel_mode_to_ncdc'] ?? '', eccd_f2_travel_modes());

    $d['public_transport_type'] = eccd_f2_pick_many($post['public_transport_type'] ?? array(), eccd_f2_transport_options());
    $d['goes_to_school_with']   = eccd_f2_pick_many($post['goes_to_school_with'] ?? array(), eccd_f2_goes_with_options());

    return $d;
}

/* ------------------------------------------------------------
   RENDER
------------------------------------------------------------ */
function eccd_f2_val($c, $key) {
    return isset($c[$key]) && $c[$key] !== null ? (string) $c[$key] : '';
}

function eccd_f2_render_row_radios($label, $name, $options, $selected) {
    return '<div class="eccd-vacc-row"><span class="eccd-vacc-label">' . eccd_h($label) . '</span>'
         . eccd_radio_group($name, $options, $selected, 3) . '</div>';
}

/* $c = current values (a `children` row, or empty array for a new child).
   $opts: 'show_growth' (bool), 'growth_note' (string) */
function eccd_f2_render($c, $opts = array()) {
    $show_growth = !empty($opts['show_growth']);
    $growth_note = isset($opts['growth_note']) ? $opts['growth_note'] : '';

    // Mother tongue: standard choice, or "Others" + the specified text
    $tongue = eccd_f2_val($c, 'first_language');
    $tongue_choice = $tongue;
    $tongue_other = '';
    if ($tongue !== '' && !in_array($tongue, eccd_f2_tongue_options(), true)) {
        $tongue_choice = 'Others';
        $tongue_other = $tongue;
    }

    $learns   = eccd_csv_to_array(eccd_f2_val($c, 'learns_at_home_with'));
    $foods    = eccd_csv_to_array(eccd_f2_val($c, 'food_normally_eaten'));
    $transport = eccd_csv_to_array(eccd_f2_val($c, 'public_transport_type'));
    $goes_with = eccd_csv_to_array(eccd_f2_val($c, 'goes_to_school_with'));

    $siblings_text = isset($c['siblings_info']) ? $c['siblings_info'] : '';
    $ece_text      = isset($c['eccd_experience_info']) ? $c['eccd_experience_info'] : '';

    ob_start();
    ?>
    <!-- ============================
         FORM 2 - CHILDREN'S PROFILE
         1-7  Personal information
    ============================= -->
    <div class="form-card">
        <h3 class="section-title">FORM 2 &nbsp; CHILDREN'S PROFILE</h3>
        <p class="section-subtitle">Follows the official ECCD Council Children's Profile form, item by item.</p>

        <div class="eccd-q">1. Personal Information</div>
        <div class="eccd-personal-grid">
            <div>
                <label class="eccd-mini-label">Last Name</label>
                <input type="text" name="last_name" class="eccd-text" value="<?php echo eccd_h(eccd_f2_val($c, 'last_name')); ?>" required>
            </div>
            <div>
                <label class="eccd-mini-label">First Name</label>
                <input type="text" name="first_name" class="eccd-text" value="<?php echo eccd_h(eccd_f2_val($c, 'first_name')); ?>" required>
            </div>
            <div>
                <label class="eccd-mini-label">Middle Name</label>
                <input type="text" name="middle_name" class="eccd-text" value="<?php echo eccd_h(eccd_f2_val($c, 'middle_name')); ?>">
            </div>
            <div>
                <label class="eccd-mini-label">Date of Birth</label>
                <input type="date" name="birthdate" class="eccd-text eccd-dob" data-age-target="child_age_display" value="<?php echo eccd_h(eccd_f2_val($c, 'birthdate')); ?>" required>
            </div>
            <div>
                <label class="eccd-mini-label">Age</label>
                <input type="text" id="child_age_display" class="eccd-text" value="" readonly tabindex="-1" placeholder="auto">
            </div>
        </div>

        <div class="eccd-q">2. Sex</div>
        <div class="eccd-options cols-4">
            <?php foreach (eccd_f2_sex_options() as $opt) { $id = 'f2_sex_' . $opt; ?>
                <label class="eccd-opt" for="<?php echo $id; ?>">
                    <input type="radio" id="<?php echo $id; ?>" name="sex" value="<?php echo $opt; ?>" <?php echo (eccd_f2_val($c, 'sex') === $opt) ? 'checked' : ''; ?> required>
                    <span><?php echo $opt; ?></span>
                </label>
            <?php } ?>
        </div>

        <div class="eccd-q">3. Birth Order</div>
        <input type="number" min="1" max="99" name="birth_order" class="eccd-text" style="max-width:160px;" value="<?php echo eccd_h(eccd_f2_val($c, 'birth_order')); ?>">

        <div class="eccd-q">4. Registered</div>
        <?php echo eccd_radio_group('is_registered', eccd_f2_yes_no(), eccd_f2_val($c, 'is_registered'), 4); ?>

        <div class="eccd-q">5. Born at</div>
        <?php echo eccd_radio_group('born_at', eccd_f2_born_at_options(), eccd_f2_val($c, 'born_at'), 4); ?>

        <div class="eccd-q">6. Mother Tongue</div>
        <?php echo eccd_radio_group('first_language', eccd_f2_tongue_options(), $tongue_choice, 5); ?>
        <?php echo eccd_other_specify('first_language', 'first_language_other', $tongue_other, 'Others, please specify'); ?>

        <div class="eccd-q">7. Other dialects spoken at home. Please specify</div>
        <input type="text" name="second_language" maxlength="50" class="eccd-text" value="<?php echo eccd_h(eccd_f2_val($c, 'second_language')); ?>">

        <div class="eccd-sub-q" style="margin-top:22px;">Other child details kept by the system (not on the paper form)</div>
        <div class="eccd-two-col">
            <div>
                <label class="eccd-mini-label">Address</label>
                <input type="text" name="address" class="eccd-text" value="<?php echo eccd_h(eccd_f2_val($c, 'address')); ?>">
            </div>
            <div>
                <label class="eccd-mini-label">Religion</label>
                <select name="religion" class="eccd-text">
                    <option value="">-- Select Religion --</option>
                    <?php
                    $religion_options = array('Roman Catholic','Christian','Islam','Iglesia ni Cristo','Seventh-day Adventist',"Jehovah's Witnesses",'Baptist','Methodist','Born Again Christian','Others');
                    $current_religion = eccd_f2_val($c, 'religion');
                    foreach ($religion_options as $opt) {
                        echo '<option value="' . eccd_h($opt) . '"' . ($current_religion === $opt ? ' selected' : '') . '>' . eccd_h($opt) . '</option>';
                    }
                    ?>
                </select>
            </div>
        </div>
    </div>

    <?php if ($show_growth) { ?>
    <!-- 8-9 Height / Weight -->
    <div class="form-card">
        <h3 class="section-title">Height and Weight</h3>
        <?php if ($growth_note !== '') { ?><p class="section-subtitle"><?php echo eccd_h($growth_note); ?></p><?php } ?>
        <div class="eccd-two-col">
            <div>
                <div class="eccd-q" style="margin-top:0;">8. Height (cm)</div>
                <input type="number" step="0.01" min="0" name="height_cm" class="eccd-text">
            </div>
            <div>
                <div class="eccd-q" style="margin-top:0;">9. Weight (kg)</div>
                <input type="number" step="0.01" min="0" name="weight_kg" class="eccd-text">
            </div>
        </div>
    </div>
    <?php } ?>

    <!-- 10-11 Health records -->
    <div class="form-card">
        <h3 class="section-title">Health Records</h3>

        <div class="eccd-q">10. Does the Child have:</div>
        <div class="eccd-options cols-3">
            <label class="eccd-opt" for="has_eccd_card">
                <input type="checkbox" id="has_eccd_card" name="has_eccd_card" value="1" <?php echo !empty($c['has_eccd_card']) ? 'checked' : ''; ?>>
                <span>ECCD Card</span>
            </label>
            <label class="eccd-opt" for="has_mother_child_book">
                <input type="checkbox" id="has_mother_child_book" name="has_mother_child_book" value="1" <?php echo !empty($c['has_mother_child_book']) ? 'checked' : ''; ?>>
                <span>Mother &amp; Child book</span>
            </label>
        </div>
        <div class="eccd-sub-q">Others, please specify</div>
        <input type="text" name="has_other_health_record" maxlength="150" class="eccd-text" style="max-width:420px;" value="<?php echo eccd_h(eccd_f2_val($c, 'has_other_health_record')); ?>">

        <div class="eccd-q">11. Vaccination and Other Health Data</div>
        <?php
        foreach (eccd_f2_vaccines() as $field => $label) {
            echo eccd_f2_render_row_radios($label, $field, eccd_f2_vaccine_answers(), eccd_f2_val($c, $field));
        }
        ?>
        <div class="eccd-vacc-row">
            <span class="eccd-vacc-label">Others</span>
            <div style="flex:1;min-width:0;">
                <input type="text" name="vaccine_others_name" maxlength="100" class="eccd-text" placeholder="Others, please specify vaccine" style="margin-bottom:8px;max-width:420px;" value="<?php echo eccd_h(eccd_f2_val($c, 'vaccine_others_name')); ?>">
                <?php echo eccd_radio_group('vaccine_others_status', eccd_f2_vaccine_answers(), eccd_f2_val($c, 'vaccine_others_status'), 3); ?>
            </div>
        </div>
    </div>

    <!-- 12 Physical attributes -->
    <div class="form-card">
        <h3 class="section-title">12. Physical Attributes</h3>

        <div class="eccd-q">12.1 Physical Deformity</div>
        <div class="eccd-options cols-4">
            <?php foreach (eccd_f2_deformities() as $field => $label) { ?>
                <label class="eccd-opt" for="<?php echo $field; ?>">
                    <input type="checkbox" id="<?php echo $field; ?>" name="<?php echo $field; ?>" value="1" <?php echo !empty($c[$field]) ? 'checked' : ''; ?>>
                    <span><?php echo eccd_h($label); ?></span>
                </label>
            <?php } ?>
        </div>

        <div class="eccd-q">12.2 Problems with:</div>
        <div class="eccd-options cols-4">
            <?php foreach (eccd_f2_problems() as $field => $label) { ?>
                <label class="eccd-opt" for="<?php echo $field; ?>">
                    <input type="checkbox" id="<?php echo $field; ?>" name="<?php echo $field; ?>" value="1" <?php echo !empty($c[$field]) ? 'checked' : ''; ?>>
                    <span><?php echo eccd_h($label); ?></span>
                </label>
            <?php } ?>
        </div>

        <div class="eccd-q">12.3 Left Handed</div>
        <?php echo eccd_radio_group('is_left_handed', eccd_f2_yes_no(), eccd_f2_val($c, 'is_left_handed'), 4); ?>
    </div>

    <!-- 13 Siblings -->
    <div class="form-card">
        <h3 class="section-title">13. Siblings</h3>
        <p class="section-subtitle">Fill in one row per sibling (up to 6): age, Male/Female, and In School/Out of School. Leave the rows blank if none.</p>
        <?php echo eccd_render_siblings($siblings_text); ?>
    </div>

    <!-- 14 Prior early childhood experience -->
    <div class="form-card">
        <h3 class="section-title">14. Prior Early Childhood Experience</h3>
        <p class="section-subtitle">Choose what the child attended for each level. Leave a level blank if none.</p>
        <?php echo eccd_render_ece($ece_text); ?>
    </div>

    <!-- 15 Other performance related inputs -->
    <div class="form-card">
        <h3 class="section-title">15. Other Performance Related Inputs</h3>

        <div class="eccd-q">15.1 Learns at Home with</div>
        <?php echo eccd_checkbox_group('learns_at_home_with', eccd_f2_learns_options(), $learns, 4); ?>

        <div class="eccd-q">15.2 Play/Interacts with Older Siblings</div>
        <?php echo eccd_radio_group('plays_with_older_siblings', eccd_f2_frequency_options(), eccd_f2_val($c, 'plays_with_older_siblings'), 4); ?>

        <div class="eccd-q">15.3 Play/Interacts with Younger Siblings</div>
        <?php echo eccd_radio_group('plays_with_younger_siblings', eccd_f2_frequency_options(), eccd_f2_val($c, 'plays_with_younger_siblings'), 4); ?>

        <div class="eccd-q">15.4 Play/Interacts with Neighbors of Same Age</div>
        <?php echo eccd_radio_group('plays_with_neighbors', eccd_f2_frequency_options(), eccd_f2_val($c, 'plays_with_neighbors'), 4); ?>
    </div>

    <!-- 16 Logistics -->
    <div class="form-card">
        <h3 class="section-title">16. Logistics</h3>

        <div class="eccd-q">16.1 Has Meal Before Going To School</div>
        <?php echo eccd_radio_group('has_meal_before_school', eccd_f2_meal_options(), eccd_f2_val($c, 'has_meal_before_school'), 5); ?>

        <div class="eccd-q">16.2 Food Normally Eaten by Child</div>
        <?php echo eccd_checkbox_group('food_normally_eaten', eccd_f2_food_options(), $foods, 5); ?>

        <div class="eccd-q">16.3 Has Baon</div>
        <?php echo eccd_radio_group('has_baon', eccd_f2_baon_options(), eccd_f2_val($c, 'has_baon'), 5); ?>

        <div class="eccd-q">16.4 Travel Time from Home to DCC (mins)</div>
        <input type="number" min="0" max="1440" name="travel_time_to_dcc_minutes" class="eccd-text" style="max-width:160px;" value="<?php echo eccd_h(eccd_f2_val($c, 'travel_time_to_dcc_minutes')); ?>">
        <div class="eccd-sub-q">16.4.1 Mode</div>
        <?php echo eccd_radio_group('travel_mode_to_dcc', eccd_f2_travel_modes(), eccd_f2_val($c, 'travel_mode_to_dcc'), 3); ?>

        <div class="eccd-q">16.5 Travel Time from Home to NCDC (mins)</div>
        <input type="number" min="0" max="1440" name="travel_time_to_ncdc_minutes" class="eccd-text" style="max-width:160px;" value="<?php echo eccd_h(eccd_f2_val($c, 'travel_time_to_ncdc_minutes')); ?>">
        <div class="eccd-sub-q">16.5.1 Mode</div>
        <?php echo eccd_radio_group('travel_mode_to_ncdc', eccd_f2_travel_modes(), eccd_f2_val($c, 'travel_mode_to_ncdc'), 3); ?>

        <div class="eccd-q">16.6 Public Transportation</div>
        <?php echo eccd_checkbox_group('public_transport_type', eccd_f2_transport_options(), $transport, 5); ?>

        <div class="eccd-q">Goes to School with</div>
        <?php echo eccd_checkbox_group('goes_to_school_with', eccd_f2_goes_with_options(), $goes_with, 4); ?>

        <div class="eccd-print-only eccd-signature">
            <div>Name of Respondent: ____________________________________</div>
            <div>Name and Signature of CDT: ____________________________________</div>
            <div>Date Conducted: ____________________________________</div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function eccd_f2_css() {
    return eccd_form_css() . <<<'CSS'
<style>
.eccd-personal-grid{display:grid;grid-template-columns:1.3fr 1.3fr 1fr 1fr 0.5fr;gap:12px;}
.eccd-two-col{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.eccd-mini-label{display:block;font-size:11px;font-weight:600;color:#777;margin-bottom:4px;}
.eccd-vacc-row{display:flex;gap:18px;align-items:center;padding:8px 0;border-bottom:1px solid #eee;}
.eccd-vacc-row:last-child{border-bottom:none;}
.eccd-vacc-label{width:110px;flex-shrink:0;font-size:13px;font-weight:600;color:#444;}
.eccd-vacc-row .eccd-options{flex:1;}
.eccd-print-only{display:none;}
.eccd-signature{margin-top:26px;font-size:13px;line-height:2.2;}
@media (max-width:900px){
  .eccd-personal-grid{grid-template-columns:1fr 1fr;}
  .eccd-two-col{grid-template-columns:1fr;}
  .eccd-vacc-row{flex-direction:column;align-items:flex-start;gap:6px;}
}
@media print{ .eccd-print-only{display:block !important;} .eccd-clear-btn{display:none !important;} }
body.dark-mode .eccd-mini-label,body.dark-mode .eccd-vacc-label{color:#cbd5e1;}
body.dark-mode .eccd-vacc-row{border-color:#334155;}
</style>
CSS;
}

function eccd_f2_js() {
    return eccd_form_js() . <<<'JS'
<script>
(function () {
    function ageFromDob(value) {
        if (!value) { return ''; }
        var p = value.split('-');
        if (p.length !== 3) { return ''; }
        var dob = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
        var now = new Date();
        if (isNaN(dob.getTime()) || dob > now) { return ''; }
        var age = now.getFullYear() - dob.getFullYear();
        var m = now.getMonth() - dob.getMonth();
        if (m < 0 || (m === 0 && now.getDate() < dob.getDate())) { age--; }
        return String(age);
    }
    document.addEventListener('DOMContentLoaded', function () {
        var inputs = document.querySelectorAll('.eccd-dob');
        for (var i = 0; i < inputs.length; i++) {
            (function (input) {
                var target = document.getElementById(input.getAttribute('data-age-target'));
                function update() { if (target) { target.value = ageFromDob(input.value); } }
                input.addEventListener('change', update);
                input.addEventListener('input', update);
                update();
            })(inputs[i]);
        }
    });
})();
</script>
JS;
}

/* ------------------------------------------------------------
   SAVE HELPERS (build UPDATE / INSERT from the column map)
------------------------------------------------------------ */

/* $base = extra [col => [type, value]] to include next to the Form 2 columns */
function eccd_f2_build_set($fields, $base = array()) {
    $types_map = eccd_f2_columns();
    $names = array();
    $types = '';
    $values = array();

    foreach ($base as $col => $tv) {
        $names[] = $col;
        $types .= $tv[0];
        $values[] = $tv[1];
    }
    foreach ($types_map as $col => $t) {
        $names[] = $col;
        $types .= $t;
        $values[] = array_key_exists($col, $fields) ? $fields[$col] : null;
    }
    return array($names, $types, $values);
}