<?php
/* ============================================================
   ECCD FORM 1 - HOME PROFILE  (full copy of the official form)
     1.A Father's Profile
     1.B Mother's Profile
     1.C Family Profile (Home Profile)

   Answers are stored as JSON in children.home_profile (no extra table).

   Used by guardian/register.php (guardian fills it in) and
   cdw/edit_child.php (CDW can correct it).

   Needs includes/eccd_form_helpers.php.
============================================================ */

require_once __DIR__ . '/eccd_form_helpers.php';

/* ------------------------------------------------------------
   OPTION LISTS  (exact wording of the official form)
------------------------------------------------------------ */
function eccd_f1_civil_status_options() {
    return array('Single', 'Married', 'Separated', 'Widower', 'Live-in');
}

/* 1.A uses "Bicolnon", 1.B uses "Bicolano" on the paper form. */
function eccd_f1_mother_tongue_options($who) {
    return array('Tagalog', 'Visayan', 'Ilocano', ($who === 'mother') ? 'Bicolano' : 'Bicolnon', 'Others');
}

function eccd_f1_education_options() {
    return array(
        'Elem./Graduate',
        'High School /Graduate',
        'College /Graduate',
        'Technical/Vocational Graduate',
        'Masteral Unit/Degree',
        'Doctoral Unit/Degree',
    );
}

function eccd_f1_occupation_options() {
    return array('Employed', 'Unemployed', 'Retired', 'OFW', 'Others');
}

function eccd_f1_dcc_age_options() {
    return array('Below 1 year old', '1 year old', '2 years old', '3 years old', '4 years old');
}

function eccd_f1_ownership_options() {
    return array('Owned', 'Rented', 'With parents', 'With relatives');
}

function eccd_f1_materials_options() {
    return array('Nipa', 'Wood', 'Concrete', 'Make shift');
}

function eccd_f1_nature_types() {
    return array('One Room', 'Multiple Rooms');
}

function eccd_f1_toilet_options_one_room() {
    return array('With toilet' => 'With toilet', 'Without toilet' => 'With no toilet');
}

function eccd_f1_toilet_options_multi_rooms() {
    return array('With toilet' => 'With toilet', 'Without toilet' => 'Without toilet');
}

function eccd_f1_play_area_options() {
    return array('With open play area', 'Without open play area');
}

function eccd_f1_rooms_options() {
    return array('With bedroom', 'With dining room', 'With sala', 'With kitchen');
}

/* Listed row by row so they appear column by column like the paper form */
function eccd_f1_utilities_options() {
    return array(
        'With running water', 'With mobile phone', 'With CD/DVD player',
        'With electricity',   'With computer',     'With Television',
        'With aircon',        'With internet',     'With radio',
    );
}

function eccd_f1_learning_options() {
    return array(
        'Magazines/comics', 'Books',               'Board Games',
        'Newspaper',        'Story/picture books', 'Puzzles',
        'Pets',             'Toys',
    );
}

/* value (stored) => label (shown) */
function eccd_f1_household_options() {
    return array(
        'Immediate Family Member' => 'Immediate Family Member (Father, Mother, Siblings)',
        'Relatives'               => 'Relatives (Aunts, Uncles, Cousins, Grandparents, Nieces)',
        'Non-Relatives'           => 'Non-Relatives (Household help / Nanny)',
    );
}

/* ------------------------------------------------------------
   DATA SHAPE
------------------------------------------------------------ */
function eccd_f1_parent_suffixes() {
    return array(
        'last_name', 'first_name', 'middle_initial', 'birthdate', 'age',
        'civil_status', 'district', 'purok_zone',
        'mother_tongue', 'mother_tongue_other', 'other_dialects',
        'education', 'occupational_status', 'occupational_other',
    );
}

function eccd_f1_home_columns() {
    return array(
        'home_ownership', 'home_materials', 'home_nature_type',
        'home_toilet', 'home_play_area', 'home_rooms',
        'home_utilities', 'home_learning_recreation', 'home_household_persons',
    );
}

/* every Form 1 answer key (the keys of the JSON saved in children.home_profile) */
function eccd_f1_all_columns() {
    $cols = array();
    foreach (array('father', 'mother') as $who) {
        foreach (eccd_f1_parent_suffixes() as $s) {
            $cols[] = $who . '_' . $s;
        }
    }
    $cols[] = 'mother_pregnant';
    $cols[] = 'mother_dcc_age_interest';
    foreach (eccd_f1_home_columns() as $c) {
        $cols[] = $c;
    }
    return $cols;
}

function eccd_f1_empty() {
    $data = array();
    foreach (eccd_f1_all_columns() as $c) {
        $data[$c] = '';
    }
    return $data;
}

/* ------------------------------------------------------------
   STORAGE
   No new table. All of Form 1 is saved as one JSON text in a single
   column on the existing `children` table: children.home_profile.
   The column is added automatically the first time it is needed
   (needs ALTER permission, which XAMPP's root user has). If it cannot
   be added, the one-line SQL is shown in the error message.
------------------------------------------------------------ */
function eccd_f1_column_sql() {
    return "ALTER TABLE children ADD COLUMN home_profile LONGTEXT NULL";
}

/* Call this BEFORE starting a transaction: ALTER TABLE commits implicitly. */
function eccd_f1_storage_ready($conn) {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $res = $conn->query("SHOW COLUMNS FROM children LIKE 'home_profile'");
    if ($res && $res->num_rows > 0) {
        $ready = true;
        return $ready;
    }

    $ready = (bool) $conn->query(eccd_f1_column_sql());
    return $ready;
}

/* kept for older call sites */
function eccd_f1_table_exists($conn) {
    return eccd_f1_storage_ready($conn);
}

function eccd_f1_storage_error() {
    return "Form 1 could not be saved because the home_profile column is missing. "
         . "Run this once in phpMyAdmin (SQL tab): " . eccd_f1_column_sql() . ";";
}

function eccd_f1_load($conn, $child_id) {
    $data = eccd_f1_empty();

    if (!eccd_f1_storage_ready($conn)) {
        return $data;
    }

    $stmt = $conn->prepare("SELECT home_profile FROM children WHERE child_id = ? LIMIT 1");
    if (!$stmt) {
        return $data;
    }
    $stmt->bind_param("i", $child_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row && $row['home_profile'] !== null && $row['home_profile'] !== '') {
        $saved = json_decode($row['home_profile'], true);
        if (is_array($saved)) {
            foreach ($data as $col => $_) {
                if (isset($saved[$col]) && $saved[$col] !== null) {
                    $data[$col] = (string) $saved[$col];
                }
            }
        }
    }

    return $data;
}

function eccd_f1_age_from_birthdate($birthdate) {
    if ($birthdate === '' || $birthdate === null || $birthdate === '0000-00-00') {
        return '';
    }
    try {
        $b = new DateTime($birthdate);
        $now = new DateTime();
        if ($b > $now) {
            return '';
        }
        return (string) $b->diff($now)->y;
    } catch (Exception $e) {
        return '';
    }
}

function eccd_f1_clean_text($value, $max = 255) {
    $value = trim((string) $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return eccd_substr($value, $max);
}

function eccd_f1_pick($value, $allowed) {
    $value = trim((string) $value);
    if (array_keys($allowed) !== range(0, count($allowed) - 1)) {
        $allowed = array_keys($allowed); // value => label list
    }
    return in_array($value, $allowed, true) ? $value : '';
}

function eccd_f1_pick_many($values, $allowed) {
    if (!is_array($values)) {
        return '';
    }
    if (array_keys($allowed) !== range(0, count($allowed) - 1)) {
        $allowed = array_keys($allowed);
    }
    $out = array();
    foreach ($allowed as $opt) {
        if (in_array($opt, $values, true)) {
            $out[] = $opt;
        }
    }
    return implode(', ', $out);
}

/* Read everything the form posted. Nothing is required. */
function eccd_f1_collect($post, &$errors) {
    $data = eccd_f1_empty();

    foreach (array('father', 'mother') as $who) {
        $p = $who . '_';

        $data[$p . 'last_name']      = eccd_f1_clean_text($post[$p . 'last_name'] ?? '', 100);
        $data[$p . 'first_name']     = eccd_f1_clean_text($post[$p . 'first_name'] ?? '', 100);
        $data[$p . 'middle_initial'] = eccd_f1_clean_text($post[$p . 'middle_initial'] ?? '', 5);

        $dob = trim((string) ($post[$p . 'birthdate'] ?? ''));
        if ($dob !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $dob);
            if (!$d || $d->format('Y-m-d') !== $dob) {
                $errors[] = ucfirst($who) . "'s date of birth is not a valid date.";
                $dob = '';
            }
        }
        $data[$p . 'birthdate'] = $dob;
        $data[$p . 'age']       = eccd_f1_age_from_birthdate($dob);

        $data[$p . 'civil_status'] = eccd_f1_pick($post[$p . 'civil_status'] ?? '', eccd_f1_civil_status_options());
        $data[$p . 'district']     = eccd_f1_clean_text($post[$p . 'district'] ?? '', 100);
        $data[$p . 'purok_zone']   = eccd_f1_clean_text($post[$p . 'purok_zone'] ?? '', 100);

        $tongue = eccd_f1_pick($post[$p . 'mother_tongue'] ?? '', eccd_f1_mother_tongue_options($who));
        $data[$p . 'mother_tongue'] = $tongue;
        $data[$p . 'mother_tongue_other'] = ($tongue === 'Others')
            ? eccd_f1_clean_text($post[$p . 'mother_tongue_other'] ?? '', 100) : '';

        $data[$p . 'other_dialects'] = eccd_f1_clean_text($post[$p . 'other_dialects'] ?? '', 255);
        $data[$p . 'education']      = eccd_f1_pick($post[$p . 'education'] ?? '', eccd_f1_education_options());

        $occ = eccd_f1_pick($post[$p . 'occupational_status'] ?? '', eccd_f1_occupation_options());
        $data[$p . 'occupational_status'] = $occ;
        $data[$p . 'occupational_other'] = ($occ === 'Others')
            ? eccd_f1_clean_text($post[$p . 'occupational_other'] ?? '', 100) : '';
    }

    $data['mother_pregnant']        = eccd_f1_pick($post['mother_pregnant'] ?? '', array('Yes', 'No'));
    $data['mother_dcc_age_interest'] = eccd_f1_pick($post['mother_dcc_age_interest'] ?? '', eccd_f1_dcc_age_options());

    $data['home_ownership'] = eccd_f1_pick($post['home_ownership'] ?? '', eccd_f1_ownership_options());
    $data['home_materials'] = eccd_f1_pick($post['home_materials'] ?? '', eccd_f1_materials_options());

    $nature = eccd_f1_pick($post['home_nature_type'] ?? '', eccd_f1_nature_types());
    $data['home_nature_type'] = $nature;

    // Only the answers that belong to the chosen column are kept
    if ($nature === 'One Room') {
        $data['home_toilet']    = eccd_f1_pick($post['nature_one_toilet'] ?? '', eccd_f1_toilet_options_one_room());
        $data['home_play_area'] = eccd_f1_pick($post['nature_one_play'] ?? '', eccd_f1_play_area_options());
        $data['home_rooms']     = '';
    } elseif ($nature === 'Multiple Rooms') {
        $data['home_toilet']    = eccd_f1_pick($post['nature_multi_toilet'] ?? '', eccd_f1_toilet_options_multi_rooms());
        $data['home_play_area'] = eccd_f1_pick($post['nature_multi_play'] ?? '', eccd_f1_play_area_options());
        $data['home_rooms']     = eccd_f1_pick_many($post['nature_multi_rooms'] ?? array(), eccd_f1_rooms_options());
    }

    $data['home_utilities']          = eccd_f1_pick_many($post['home_utilities'] ?? array(), eccd_f1_utilities_options());
    $data['home_learning_recreation'] = eccd_f1_pick_many($post['home_learning_recreation'] ?? array(), eccd_f1_learning_options());
    $data['home_household_persons']  = eccd_f1_pick_many($post['home_household_persons'] ?? array(), eccd_f1_household_options());

    return $data;
}

function eccd_f1_has_any($data) {
    foreach ($data as $v) {
        if ($v !== '' && $v !== null) {
            return true;
        }
    }
    return false;
}

/* Save the child's home profile. Returns true/false. */
function eccd_f1_save($conn, $child_id, $data, &$error_message = '') {
    if (!eccd_f1_storage_ready($conn)) {
        $error_message = eccd_f1_storage_error();
        return false;
    }

    $clean = array();
    foreach (eccd_f1_all_columns() as $c) {
        $v = isset($data[$c]) ? (string) $data[$c] : '';
        if ($v !== '') {
            $clean[$c] = $v;
        }
    }

    $json = empty($clean) ? null : json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $conn->prepare("UPDATE children SET home_profile = ? WHERE child_id = ?");
    if (!$stmt) {
        $error_message = 'Failed to prepare home profile save: ' . $conn->error;
        return false;
    }

    $stmt->bind_param("si", $json, $child_id);

    if (!$stmt->execute()) {
        $error_message = 'Failed to save home profile: ' . $stmt->error;
        $stmt->close();
        return false;
    }

    $stmt->close();
    return true;
}

/* Keep the older columns on `children` (mother_name, father_name,
   occupation, address) filled, so the pages that still show them
   keep working. Contact numbers are not part of Form 1 and are left
   untouched. Only a parent who actually has data gets updated. */
function eccd_f1_sync_children($conn, $child_id, $data) {
    $sets = array();
    $types = '';
    $values = array();

    foreach (array('mother', 'father') as $who) {
        $p = $who . '_';

        $has_name = ($data[$p . 'first_name'] !== '' || $data[$p . 'last_name'] !== '');
        if ($has_name) {
            $mi = ($data[$p . 'middle_initial'] !== '') ? rtrim($data[$p . 'middle_initial'], '.') . '.' : '';
            $name = trim(implode(' ', array_filter(array($data[$p . 'first_name'], $mi, $data[$p . 'last_name']))));
            $sets[] = $who . '_name = ?';
            $types .= 's';
            $values[] = $name;
        }

        if ($data[$p . 'occupational_status'] !== '') {
            $occ = ($data[$p . 'occupational_status'] === 'Others' && $data[$p . 'occupational_other'] !== '')
                ? $data[$p . 'occupational_other'] : $data[$p . 'occupational_status'];
            $sets[] = $who . '_occupation = ?';
            $types .= 's';
            $values[] = $occ;
        }

        if ($data[$p . 'district'] !== '' || $data[$p . 'purok_zone'] !== '') {
            $parts = array();
            if ($data[$p . 'district'] !== '') {
                $parts[] = 'District: ' . $data[$p . 'district'];
            }
            if ($data[$p . 'purok_zone'] !== '') {
                $parts[] = 'Purok/Zone: ' . $data[$p . 'purok_zone'];
            }
            $sets[] = $who . '_address = ?';
            $types .= 's';
            $values[] = implode(', ', $parts);
        }
    }

    if (empty($sets)) {
        return true;
    }

    $types .= 'i';
    $values[] = (int) $child_id;

    $stmt = $conn->prepare("UPDATE children SET " . implode(', ', $sets) . " WHERE child_id = ?");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param($types, ...$values);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/* ------------------------------------------------------------
   RENDER
------------------------------------------------------------ */
function eccd_f1_render_personal_block($who, $d) {
    $p = $who . '_';
    ob_start();
    ?>
    <div class="eccd-personal-grid">
        <div>
            <label class="eccd-mini-label">Last Name</label>
            <input type="text" class="eccd-text" name="<?php echo $p; ?>last_name" maxlength="100" value="<?php echo eccd_h($d[$p . 'last_name']); ?>">
        </div>
        <div>
            <label class="eccd-mini-label">First Name</label>
            <input type="text" class="eccd-text" name="<?php echo $p; ?>first_name" maxlength="100" value="<?php echo eccd_h($d[$p . 'first_name']); ?>">
        </div>
        <div>
            <label class="eccd-mini-label">Middle Initial</label>
            <input type="text" class="eccd-text" name="<?php echo $p; ?>middle_initial" maxlength="5" value="<?php echo eccd_h($d[$p . 'middle_initial']); ?>">
        </div>
        <div>
            <label class="eccd-mini-label">Date of Birth</label>
            <input type="date" class="eccd-text eccd-dob" data-age-target="<?php echo $p; ?>age_display" name="<?php echo $p; ?>birthdate" value="<?php echo eccd_h($d[$p . 'birthdate']); ?>">
        </div>
        <div>
            <label class="eccd-mini-label">Age</label>
            <input type="text" class="eccd-text" id="<?php echo $p; ?>age_display" value="<?php echo eccd_h($d[$p . 'age']); ?>" readonly tabindex="-1" placeholder="auto">
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function eccd_f1_render_address_block($who, $d) {
    $p = $who . '_';
    ob_start();
    ?>
    <div class="eccd-two-col">
        <div>
            <label class="eccd-mini-label">District</label>
            <input type="text" class="eccd-text" name="<?php echo $p; ?>district" maxlength="100" value="<?php echo eccd_h($d[$p . 'district']); ?>">
        </div>
        <div>
            <label class="eccd-mini-label">Purok/Zone</label>
            <input type="text" class="eccd-text" name="<?php echo $p; ?>purok_zone" maxlength="100" value="<?php echo eccd_h($d[$p . 'purok_zone']); ?>">
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/* FORM 1.A (father) and FORM 1.B (mother), numbered exactly like the paper form:
     1.A  1 Personal Info, 2 Civil Status, 3 Address, 4 Mother Tongue,
          5 Other dialects, 6 Educational Attainment, 7 Occupational Status
     1.B  1 Personal Info (+ Address), 2 Pregnant?, 3 Civil Status, 4 Mother Tongue,
          5 Other dialects, 6 Educational Attainment, 7 Occupational Status,
          8 Age interested to put child in a Day Care Center                      */
function eccd_f1_render_parent($who, $d) {
    $p = $who . '_';
    $is_mother = ($who === 'mother');

    $html  = '<div class="form-card">';
    $html .= '<h3 class="section-title">' . ($is_mother ? "FORM 1.B &nbsp; MOTHER'S PROFILE" : "FORM 1.A &nbsp; FATHER'S PROFILE") . '</h3>';
    $html .= '<p class="section-subtitle">Optional &mdash; leave blank if not applicable.</p>';

    $html .= '<div class="eccd-q">1. Personal Information</div>';
    $html .= eccd_f1_render_personal_block($who, $d);

    if ($is_mother) {
        $html .= '<div class="eccd-sub-q">Address</div>';
        $html .= eccd_f1_render_address_block($who, $d);

        $html .= '<div class="eccd-q">2. Pregnant?</div>';
        $html .= eccd_radio_group('mother_pregnant', array('Yes', 'No'), $d['mother_pregnant'], 5);

        $html .= '<div class="eccd-q">3. Civil Status</div>';
        $html .= eccd_radio_group($p . 'civil_status', eccd_f1_civil_status_options(), $d[$p . 'civil_status'], 5);
    } else {
        $html .= '<div class="eccd-q">2. Civil Status</div>';
        $html .= eccd_radio_group($p . 'civil_status', eccd_f1_civil_status_options(), $d[$p . 'civil_status'], 5);

        $html .= '<div class="eccd-q">3. Address</div>';
        $html .= eccd_f1_render_address_block($who, $d);
    }

    $html .= '<div class="eccd-q">4. Mother Tongue</div>';
    $html .= eccd_radio_group($p . 'mother_tongue', eccd_f1_mother_tongue_options($who), $d[$p . 'mother_tongue'], 5);
    $html .= eccd_other_specify($p . 'mother_tongue', $p . 'mother_tongue_other', $d[$p . 'mother_tongue_other'], 'Others, please specify');

    $html .= '<div class="eccd-q">5. Other dialects spoken at home. Please specify</div>';
    $html .= '<input type="text" class="eccd-text" name="' . $p . 'other_dialects" maxlength="255" value="' . eccd_h($d[$p . 'other_dialects']) . '">';

    $html .= '<div class="eccd-q">6. Educational Attainment</div>';
    $html .= eccd_radio_group($p . 'education', eccd_f1_education_options(), $d[$p . 'education'], 3);

    $html .= '<div class="eccd-q">7. Occupational Status</div>';
    $html .= eccd_radio_group($p . 'occupational_status', eccd_f1_occupation_options(), $d[$p . 'occupational_status'], 5);
    $html .= eccd_other_specify($p . 'occupational_status', $p . 'occupational_other', $d[$p . 'occupational_other'], 'Others, please specify');

    if ($is_mother) {
        $html .= '<div class="eccd-q">8. At what age are you interested to put your child in a Day Care Center</div>';
        $html .= eccd_radio_group('mother_dcc_age_interest', eccd_f1_dcc_age_options(), $d['mother_dcc_age_interest'], 5);
    }

    $html .= '</div>';
    return $html;
}

function eccd_f1_render_mother($d) {
    return eccd_f1_render_parent('mother', $d);
}

function eccd_f1_render_family($d) {
    $rooms_checked     = eccd_csv_to_array($d['home_rooms']);
    $utilities_checked = eccd_csv_to_array($d['home_utilities']);
    $learning_checked  = eccd_csv_to_array($d['home_learning_recreation']);
    $household_checked = eccd_csv_to_array($d['home_household_persons']);

    $nature = $d['home_nature_type'];
    $one_toilet = ($nature === 'One Room') ? $d['home_toilet'] : '';
    $one_play   = ($nature === 'One Room') ? $d['home_play_area'] : '';
    $multi_toilet = ($nature === 'Multiple Rooms') ? $d['home_toilet'] : '';
    $multi_play   = ($nature === 'Multiple Rooms') ? $d['home_play_area'] : '';

    ob_start();
    ?>
    <div class="form-card">
        <h3 class="section-title">FORM 1.C &nbsp; FAMILY PROFILE</h3>
        <p class="section-subtitle">A. Home Profile</p>

        <div class="eccd-q">1. Ownership</div>
        <?php echo eccd_radio_group('home_ownership', eccd_f1_ownership_options(), $d['home_ownership'], 4); ?>

        <div class="eccd-q">2. Materials</div>
        <?php echo eccd_radio_group('home_materials', eccd_f1_materials_options(), $d['home_materials'], 4); ?>

        <div class="eccd-q">3. Nature</div>
        <?php echo eccd_radio_group('home_nature_type', eccd_f1_nature_types(), $nature, 2); ?>

        <div class="eccd-nature-grid">
            <div class="eccd-nature-col" data-nature="One Room">
                <div class="eccd-sub-q">One Room</div>
                <?php echo eccd_radio_group('nature_one_toilet', eccd_f1_toilet_options_one_room(), $one_toilet, 1); ?>
                <div style="height:8px;"></div>
                <?php echo eccd_radio_group('nature_one_play', eccd_f1_play_area_options(), $one_play, 1); ?>
            </div>

            <div class="eccd-nature-col" data-nature="Multiple Rooms">
                <div class="eccd-sub-q">Multiple Rooms</div>
                <?php echo eccd_radio_group('nature_multi_toilet', eccd_f1_toilet_options_multi_rooms(), $multi_toilet, 1); ?>
                <div style="height:8px;"></div>
                <?php echo eccd_radio_group('nature_multi_play', eccd_f1_play_area_options(), $multi_play, 1); ?>
            </div>

            <div class="eccd-nature-col" data-nature="Multiple Rooms">
                <div class="eccd-sub-q">Rooms (Multiple Rooms)</div>
                <?php echo eccd_checkbox_group('nature_multi_rooms', eccd_f1_rooms_options(), $rooms_checked, 1); ?>
            </div>
        </div>

        <div class="eccd-q">4. Utilities and Appliances</div>
        <?php echo eccd_checkbox_group('home_utilities', eccd_f1_utilities_options(), $utilities_checked, 3); ?>

        <div class="eccd-q">5. Learning and Recreation/Fun &amp; Games</div>
        <?php echo eccd_checkbox_group('home_learning_recreation', eccd_f1_learning_options(), $learning_checked, 3); ?>

        <div class="eccd-q">6. Persons Staying in the Same Household</div>
        <?php echo eccd_checkbox_group('home_household_persons', eccd_f1_household_options(), $household_checked, 3); ?>

        <div class="eccd-print-only eccd-signature">
            <div>Name and Signature of CDT: ____________________________________</div>
            <div>Date Conducted: ____________________________________</div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/* All three parts of Form 1 (1.A, 1.B, 1.C) */
function eccd_f1_render_all($d) {
    return eccd_f1_render_parent('father', $d)
         . eccd_f1_render_mother($d)
         . eccd_f1_render_family($d);
}

function eccd_f1_css() {
    return <<<'CSS'
<style>
.eccd-personal-grid{display:grid;grid-template-columns:1.3fr 1.3fr 0.6fr 1fr 0.5fr;gap:12px;}
.eccd-two-col{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.eccd-mini-label{display:block;font-size:11px;font-weight:600;color:#777;margin-bottom:4px;}
.eccd-nature-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-top:10px;padding:14px;border:1px solid #e3e3e3;border-radius:10px;}
.eccd-nature-col.is-off{opacity:0.35;}
.eccd-print-only{display:none;}
.eccd-signature{margin-top:26px;font-size:13px;line-height:2.2;}
@media (max-width:900px){
  .eccd-personal-grid{grid-template-columns:1fr 1fr;}
  .eccd-nature-grid{grid-template-columns:1fr;}
  .eccd-two-col{grid-template-columns:1fr;}
}
@media print{
  .eccd-print-only{display:block !important;}
  .eccd-clear-btn{display:none !important;}
  .eccd-text{border:none !important;border-bottom:1px solid #000 !important;border-radius:0 !important;background:transparent !important;}
  .eccd-opt input{accent-color:#000;}
}
body.dark-mode .eccd-mini-label{color:#cbd5e1;}
body.dark-mode .eccd-nature-grid{border-color:#334155;}
</style>
CSS;
}

function eccd_f1_js() {
    return <<<'JS'
<script>
(function () {
    function ageFromDob(value) {
        if (!value) { return ''; }
        var parts = value.split('-');
        if (parts.length !== 3) { return ''; }
        var dob = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
        var now = new Date();
        if (isNaN(dob.getTime()) || dob > now) { return ''; }
        var age = now.getFullYear() - dob.getFullYear();
        var m = now.getMonth() - dob.getMonth();
        if (m < 0 || (m === 0 && now.getDate() < dob.getDate())) { age--; }
        return String(age);
    }

    function bindDob() {
        var inputs = document.querySelectorAll('.eccd-dob');
        for (var i = 0; i < inputs.length; i++) {
            (function (input) {
                var target = document.getElementById(input.getAttribute('data-age-target'));
                function update() { if (target) { target.value = ageFromDob(input.value); } }
                input.addEventListener('change', update);
                input.addEventListener('input', update);
            })(inputs[i]);
        }
    }

    function refreshNature() {
        var selected = '';
        var radios = document.querySelectorAll('input[name="home_nature_type"]');
        for (var i = 0; i < radios.length; i++) {
            if (radios[i].checked) { selected = radios[i].value; }
        }
        var cols = document.querySelectorAll('.eccd-nature-col');
        for (var c = 0; c < cols.length; c++) {
            var active = (selected === '' || cols[c].getAttribute('data-nature') === selected);
            if (active) { cols[c].classList.remove('is-off'); } else { cols[c].classList.add('is-off'); }
            var inputs = cols[c].querySelectorAll('input');
            for (var k = 0; k < inputs.length; k++) { inputs[k].disabled = !active; }
        }
    }

    document.addEventListener('change', function (e) {
        if (e.target && e.target.name === 'home_nature_type') { refreshNature(); }
    });
    document.addEventListener('DOMContentLoaded', function () { bindDob(); refreshNature(); });
})();
</script>
JS;
}