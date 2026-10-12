<?php
/* ============================================================
   ECCD FORM HELPERS
   Shared by:
     - cdw/add_child.php        (Form 2 - Children's Profile)
     - cdw/edit_child.php       (Form 2 - Children's Profile)
     - guardian/health_info.php (Form 2 items submitted by guardian)
     - guardian/register.php    (Form 1 - Home Profile, via eccd_form1.php)

   Siblings (item 13) and Prior Early Childhood Experience (item 14)
   are filled in with the same boxes as the paper form, but they are
   still SAVED in the existing text columns (children.siblings_info and
   children.eccd_experience_info) in this fixed format:

       siblings : "Age 8, Male, In School"          (one line per sibling)
       ECE      : "Nursery: Private Day Care"       (one line per level)

   That way child_profile.php, the guardian submission review and every
   other page that already prints those two columns keep working, and
   no database change is needed for them.
============================================================ */

/* substr that does not need the mbstring extension */
if (!function_exists('eccd_substr')) {
    function eccd_substr($value, $length) {
        $value = (string) $value;
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }
        if (preg_match('/^.{0,' . (int) $length . '}/su', $value, $m)) {
            return $m[0];
        }
        return substr($value, 0, $length);
    }
}

if (!function_exists('eccd_h')) {
    function eccd_h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

/* ------------------------------------------------------------
   OPTION LISTS (exact wording of the official Form 2)
------------------------------------------------------------ */
function eccd_sibling_max_rows() {
    return 6; // Form 2 item 13 has 6 rows
}

function eccd_ece_levels() {
    return array('Nursery', 'Kindergarten', 'Preparatory');
}

/* Order matches the paper form, read row by row:
   Private Pre School | Private Day Care | Church-based | Others
   Public Pre-School  | Public Day Care  | Home-based              */
function eccd_ece_options() {
    return array(
        'Private Pre School',
        'Private Day Care',
        'Church-based',
        'Others',
        'Public Pre-School',
        'Public Day Care',
        'Home-based',
    );
}

function eccd_mother_tongue_options_form2() {
    return array('Tagalog', 'Visayan', 'Ilocano', 'Bicolnon');
}

/* ------------------------------------------------------------
   SIBLINGS  (Form 2 item 13)
------------------------------------------------------------ */

/* Turn the stored text back into rows for the form.
   Lines that do not fit the format (old free-text entries) are
   returned in 'legacy' so nothing is lost. */
function eccd_parse_siblings($text) {
    $rows = array();
    $legacy = array();
    $max = eccd_sibling_max_rows();

    foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        if (preg_match('/^Age\s+(\d{1,2})\s*,\s*(Male|Female)\s*,\s*(In School|Out of School)$/i', $line, $m)
            && count($rows) < $max) {
            $rows[] = array(
                'age'    => (string) ((int) $m[1]),
                'sex'    => ucfirst(strtolower($m[2])),
                'school' => (stripos($m[3], 'out') === 0) ? 'Out of School' : 'In School',
            );
        } else {
            $legacy[] = $line;
        }
    }

    return array('rows' => $rows, 'legacy' => implode("\n", $legacy));
}

/* Read the submitted sibling rows. Returns the text to store (or null
   when empty). Problems (half-filled rows) are added to $errors. */
function eccd_collect_siblings($post, &$errors) {
    $ages    = isset($post['sibling_age']) && is_array($post['sibling_age']) ? $post['sibling_age'] : array();
    $sexes   = isset($post['sibling_sex']) && is_array($post['sibling_sex']) ? $post['sibling_sex'] : array();
    $schools = isset($post['sibling_school']) && is_array($post['sibling_school']) ? $post['sibling_school'] : array();

    $lines = array();

    for ($i = 0; $i < eccd_sibling_max_rows(); $i++) {
        $age    = isset($ages[$i]) ? trim((string) $ages[$i]) : '';
        $sex    = isset($sexes[$i]) ? trim((string) $sexes[$i]) : '';
        $school = isset($schools[$i]) ? trim((string) $schools[$i]) : '';

        if ($age === '' && $sex === '' && $school === '') {
            continue;
        }

        $valid_sex    = in_array($sex, array('Male', 'Female'), true);
        $valid_school = in_array($school, array('In School', 'Out of School'), true);

        if ($age === '' || !ctype_digit($age) || (int) $age > 99 || !$valid_sex || !$valid_school) {
            $errors[] = 'Sibling row ' . ($i + 1) . ' is incomplete. Please give the age, Male/Female, and In School/Out of School.';
            continue;
        }

        $lines[] = 'Age ' . (int) $age . ', ' . $sex . ', ' . $school;
    }

    // keep old free-text lines unless the user chose to discard them
    $legacy = isset($post['siblings_legacy']) ? trim((string) $post['siblings_legacy']) : '';
    if ($legacy !== '' && empty($post['siblings_legacy_discard'])) {
        foreach (preg_split('/\r\n|\r|\n/', $legacy) as $l) {
            $l = trim($l);
            if ($l !== '') {
                $lines[] = $l;
            }
        }
    }

    return empty($lines) ? null : implode("\n", $lines);
}

/* ------------------------------------------------------------
   PRIOR EARLY CHILDHOOD EXPERIENCE  (Form 2 item 14)
------------------------------------------------------------ */
function eccd_parse_ece($text) {
    $levels = eccd_ece_levels();
    $options = eccd_ece_options();
    $result = array();
    foreach ($levels as $lv) {
        $result[$lv] = array('choice' => '', 'other' => '');
    }
    $legacy = array();

    foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $matched = false;

        if (preg_match('/^(Nursery|Kindergarten|Preparatory)\s*:\s*(.+)$/i', $line, $m)) {
            $level = ucfirst(strtolower($m[1]));
            $value = trim($m[2]);

            if ($result[$level]['choice'] === '') {
                foreach ($options as $opt) {
                    if ($opt === 'Others') {
                        continue;
                    }
                    if (strcasecmp($value, $opt) === 0) {
                        $result[$level]['choice'] = $opt;
                        $matched = true;
                        break;
                    }
                }

                if (!$matched && preg_match('/^Others(?:\s*\((.*)\))?$/i', $value, $om)) {
                    $result[$level]['choice'] = 'Others';
                    $result[$level]['other'] = isset($om[1]) ? trim($om[1]) : '';
                    $matched = true;
                }
            }
        }

        if (!$matched) {
            $legacy[] = $line;
        }
    }

    $result['legacy'] = implode("\n", $legacy);
    return $result;
}

function eccd_collect_ece($post) {
    $choices = isset($post['ece']) && is_array($post['ece']) ? $post['ece'] : array();
    $others  = isset($post['ece_other']) && is_array($post['ece_other']) ? $post['ece_other'] : array();
    $options = eccd_ece_options();

    $lines = array();

    foreach (eccd_ece_levels() as $level) {
        $choice = isset($choices[$level]) ? trim((string) $choices[$level]) : '';
        if ($choice === '' || !in_array($choice, $options, true)) {
            continue;
        }

        if ($choice === 'Others') {
            $other = isset($others[$level]) ? trim((string) $others[$level]) : '';
            $other = str_replace(array("\r", "\n", '(', ')'), ' ', $other);
            $other = trim(preg_replace('/\s+/', ' ', $other));
            $lines[] = $level . ': Others' . ($other !== '' ? ' (' . $other . ')' : '');
        } else {
            $lines[] = $level . ': ' . $choice;
        }
    }

    $legacy = isset($post['ece_legacy']) ? trim((string) $post['ece_legacy']) : '';
    if ($legacy !== '' && empty($post['ece_legacy_discard'])) {
        foreach (preg_split('/\r\n|\r|\n/', $legacy) as $l) {
            $l = trim($l);
            if ($l !== '') {
                $lines[] = $l;
            }
        }
    }

    return empty($lines) ? null : implode("\n", $lines);
}

/* ------------------------------------------------------------
   GENERIC RENDERERS (radio / checkbox boxes like the paper form)
------------------------------------------------------------ */
function eccd_id($name, $value) {
    return 'f_' . preg_replace('/[^a-z0-9]+/i', '_', $name . '_' . $value);
}

/* One-choice group. $selected is the stored value.
   $options can be a plain list, or value => label. */
function eccd_radio_group($name, $options, $selected, $cols = 4) {
    $html = '<div class="eccd-options cols-' . (int) $cols . '">';
    foreach ($options as $key => $label) {
        $value = is_int($key) ? $label : $key;
        $id = eccd_id($name, $value);
        $checked = ((string) $selected === (string) $value) ? ' checked' : '';
        $html .= '<label class="eccd-opt" for="' . eccd_h($id) . '">'
               . '<input type="radio" id="' . eccd_h($id) . '" name="' . eccd_h($name) . '" value="' . eccd_h($value) . '"' . $checked . '> '
               . '<span>' . eccd_h($label) . '</span></label>';
    }
    $html .= '</div>';
    return $html;
}

/* Many-choice group. $checked is an array of stored values.
   $options can be a plain list, or value => label. */
function eccd_checkbox_group($name, $options, $checked, $cols = 3) {
    $html = '<div class="eccd-options cols-' . (int) $cols . '">';
    foreach ($options as $key => $label) {
        $value = is_int($key) ? $label : $key;
        $id = eccd_id($name, $value);
        $is = in_array($value, $checked, true) ? ' checked' : '';
        $html .= '<label class="eccd-opt" for="' . eccd_h($id) . '">'
               . '<input type="checkbox" id="' . eccd_h($id) . '" name="' . eccd_h($name) . '[]" value="' . eccd_h($value) . '"' . $is . '> '
               . '<span>' . eccd_h($label) . '</span></label>';
    }
    $html .= '</div>';
    return $html;
}

/* "Others, please specify ____" box that only shows when $groupName
   has "Others" selected/ticked. */
function eccd_other_specify($groupName, $inputName, $value, $placeholder = 'Please specify') {
    return '<div class="eccd-other-wrap" data-for="' . eccd_h($groupName) . '">'
         . '<input type="text" name="' . eccd_h($inputName) . '" class="eccd-text" placeholder="' . eccd_h($placeholder) . '" value="' . eccd_h($value) . '">'
         . '</div>';
}

/* Turn "A, B, C" (how the old columns were stored) into an array. */
function eccd_csv_to_array($value) {
    if ($value === null || trim((string) $value) === '') {
        return array();
    }
    return array_values(array_filter(array_map('trim', explode(',', (string) $value)), function ($v) {
        return $v !== '';
    }));
}

/* ------------------------------------------------------------
   FORM 2 ITEM 13: SIBLINGS  (HTML)
------------------------------------------------------------ */
function eccd_render_siblings($stored_text) {
    $parsed = eccd_parse_siblings($stored_text);
    $rows = $parsed['rows'];
    $max = eccd_sibling_max_rows();

    $html  = '<div class="eccd-sibling-table">';
    $html .= '<div class="eccd-sibling-head"><span>#</span><span>Age</span><span>Sex</span><span>Education: In School or Out of School</span><span></span></div>';

    for ($i = 0; $i < $max; $i++) {
        $age    = isset($rows[$i]) ? $rows[$i]['age'] : '';
        $sex    = isset($rows[$i]) ? $rows[$i]['sex'] : '';
        $school = isset($rows[$i]) ? $rows[$i]['school'] : '';

        $html .= '<div class="eccd-sibling-row" data-row="' . $i . '">';
        $html .= '<span class="eccd-sibling-no">' . ($i + 1) . '</span>';
        $html .= '<input type="number" min="0" max="99" class="eccd-text eccd-age" name="sibling_age[' . $i . ']" value="' . eccd_h($age) . '" placeholder="Age">';

        $html .= '<span class="eccd-inline-options">';
        foreach (array('Male', 'Female') as $opt) {
            $id = 'sibling_sex_' . $i . '_' . $opt;
            $html .= '<label class="eccd-opt" for="' . $id . '"><input type="radio" id="' . $id . '" name="sibling_sex[' . $i . ']" value="' . $opt . '"' . ($sex === $opt ? ' checked' : '') . '> <span>' . $opt . '</span></label>';
        }
        $html .= '</span>';

        $html .= '<span class="eccd-inline-options">';
        foreach (array('In School', 'Out of School') as $opt) {
            $id = 'sibling_school_' . $i . '_' . str_replace(' ', '', $opt);
            $html .= '<label class="eccd-opt" for="' . $id . '"><input type="radio" id="' . $id . '" name="sibling_school[' . $i . ']" value="' . $opt . '"' . ($school === $opt ? ' checked' : '') . '> <span>' . $opt . '</span></label>';
        }
        $html .= '</span>';

        $html .= '<button type="button" class="eccd-clear-btn" onclick="eccdClearSiblingRow(' . $i . ')">Clear</button>';
        $html .= '</div>';
    }
    $html .= '</div>';

    if ($parsed['legacy'] !== '') {
        $html .= '<div class="eccd-legacy-note">'
               . '<strong>Earlier entry (old free-text format):</strong><br>'
               . nl2br(eccd_h($parsed['legacy']))
               . '<input type="hidden" name="siblings_legacy" value="' . eccd_h($parsed['legacy']) . '">'
               . '<label class="eccd-opt" style="margin-top:8px;"><input type="checkbox" name="siblings_legacy_discard" value="1"> <span>Remove this old entry when saving (after re-entering the siblings in the table above)</span></label>'
               . '</div>';
    }

    return $html;
}

/* ------------------------------------------------------------
   FORM 2 ITEM 14: PRIOR EARLY CHILDHOOD EXPERIENCE  (HTML)
------------------------------------------------------------ */
function eccd_render_ece($stored_text) {
    $parsed = eccd_parse_ece($stored_text);
    $options = eccd_ece_options();
    $numbers = array('Nursery' => '14.1', 'Kindergarten' => '14.2', 'Preparatory' => '14.3');

    $html = '';
    foreach (eccd_ece_levels() as $level) {
        $name = 'ece[' . $level . ']';
        $choice = $parsed[$level]['choice'];
        $other  = $parsed[$level]['other'];

        $html .= '<div class="eccd-ece-level">';
        $html .= '<div class="eccd-ece-title">' . $numbers[$level] . ' ' . eccd_h($level)
               . ' <button type="button" class="eccd-clear-btn" onclick="eccdClearRadios(\'' . eccd_h($name) . '\')">Clear</button></div>';
        $html .= eccd_radio_group($name, $options, $choice, 4);
        $html .= eccd_other_specify($name, 'ece_other[' . $level . ']', $other, 'Others, please specify');
        $html .= '</div>';
    }

    if ($parsed['legacy'] !== '') {
        $html .= '<div class="eccd-legacy-note">'
               . '<strong>Earlier entry (old free-text format):</strong><br>'
               . nl2br(eccd_h($parsed['legacy']))
               . '<input type="hidden" name="ece_legacy" value="' . eccd_h($parsed['legacy']) . '">'
               . '<label class="eccd-opt" style="margin-top:8px;"><input type="checkbox" name="ece_legacy_discard" value="1"> <span>Remove this old entry when saving (after choosing the options above)</span></label>'
               . '</div>';
    }

    return $html;
}

/* ------------------------------------------------------------
   CSS + JS  (print once per page, inside <head> / before </body>)
------------------------------------------------------------ */
function eccd_form_css() {
    return <<<'CSS'
<style>
.eccd-options{display:grid;gap:10px 18px;padding:2px 0;}
.eccd-options.cols-2{grid-template-columns:repeat(2,minmax(0,1fr));}
.eccd-options.cols-3{grid-template-columns:repeat(3,minmax(0,1fr));}
.eccd-options.cols-4{grid-template-columns:repeat(4,minmax(0,1fr));}
.eccd-options.cols-5{grid-template-columns:repeat(5,minmax(0,1fr));}
.eccd-opt{display:flex;align-items:center;gap:8px;font-size:13px;color:#333;cursor:pointer;line-height:1.35;}
.eccd-opt input{width:16px;height:16px;flex-shrink:0;cursor:pointer;accent-color:#2E7D32;}
.eccd-inline-options{display:flex;gap:16px;flex-wrap:wrap;align-items:center;}
.eccd-text{width:100%;border:1px solid #cfcfcf;border-radius:8px;padding:10px 12px;font-size:13px;font-family:'Inter',sans-serif;background:#fff;color:#333;outline:none;}
.eccd-text:focus{border-color:#2E7D32;box-shadow:0 0 0 3px rgba(46,125,50,0.08);}
.eccd-other-wrap{display:none;margin-top:10px;max-width:420px;}
.eccd-other-wrap.show{display:block;}
.eccd-clear-btn{border:1px solid #d0d0d0;background:#f5f5f5;color:#555;border-radius:6px;padding:4px 10px;font-size:11px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;}
.eccd-clear-btn:hover{background:#e9e9e9;}
.eccd-sibling-table{border:1px solid #dcdcdc;border-radius:10px;overflow:hidden;}
.eccd-sibling-head,.eccd-sibling-row{display:grid;grid-template-columns:34px 90px 170px 1fr 70px;gap:12px;align-items:center;padding:10px 14px;}
.eccd-sibling-head{background:#f7f7f7;font-size:12px;font-weight:700;color:#555;border-bottom:1px solid #dcdcdc;}
.eccd-sibling-row{border-bottom:1px solid #eee;}
.eccd-sibling-row:last-child{border-bottom:none;}
.eccd-sibling-no{font-size:12px;font-weight:700;color:#888;}
.eccd-age{padding:8px 10px;}
.eccd-ece-level{padding:14px 0;border-bottom:1px solid #eee;}
.eccd-ece-level:last-of-type{border-bottom:none;}
.eccd-ece-title{font-size:13px;font-weight:700;color:#2E7D32;margin-bottom:10px;display:flex;align-items:center;gap:10px;}
.eccd-legacy-note{margin-top:14px;padding:12px 14px;border:1px dashed #e0a800;background:#fff8e1;border-radius:10px;font-size:12px;color:#7a5b00;line-height:1.6;}
.eccd-block-title{font-family:'Poppins',sans-serif;font-size:14px;font-weight:700;color:#2f2f2f;margin:20px 0 10px;}
.eccd-block-title:first-child{margin-top:0;}
.eccd-q{font-size:13px;font-weight:600;color:#444;margin:16px 0 8px;}
.eccd-q:first-child{margin-top:0;}
.eccd-sub-q{font-size:12px;font-weight:600;color:#666;margin:12px 0 6px;}
@media (max-width:900px){
  .eccd-options.cols-3,.eccd-options.cols-4,.eccd-options.cols-5{grid-template-columns:repeat(2,minmax(0,1fr));}
  .eccd-sibling-head{display:none;}
  .eccd-sibling-row{grid-template-columns:28px 1fr;row-gap:8px;}
  .eccd-sibling-row .eccd-inline-options,.eccd-sibling-row .eccd-clear-btn{grid-column:2;justify-self:start;}
}
@media (max-width:520px){
  .eccd-options.cols-2,.eccd-options.cols-3,.eccd-options.cols-4,.eccd-options.cols-5{grid-template-columns:1fr;}
}
body.dark-mode .eccd-opt{color:#e5e5e5;}
body.dark-mode .eccd-text{background:#2a2a2a;color:#fff;border-color:#444;}
body.dark-mode .eccd-clear-btn{background:#2a2a2a;color:#e5e5e5;border-color:#444;}
body.dark-mode .eccd-sibling-table{border-color:#334155;}
body.dark-mode .eccd-sibling-head{background:#1e293b;color:#cbd5e1;border-color:#334155;}
body.dark-mode .eccd-sibling-row,body.dark-mode .eccd-ece-level{border-color:#334155;}
body.dark-mode .eccd-ece-title{color:#4ade80;}
body.dark-mode .eccd-block-title,body.dark-mode .eccd-q,body.dark-mode .eccd-sub-q{color:#e5e5e5;}
body.dark-mode .eccd-legacy-note{background:#3a3000;color:#ffe08a;border-color:#8a6d00;}
</style>
CSS;
}

function eccd_form_js() {
    return <<<'JS'
<script>
function eccdClearRadios(name) {
    var inputs = document.querySelectorAll('input[type="radio"]');
    for (var i = 0; i < inputs.length; i++) {
        if (inputs[i].name === name) { inputs[i].checked = false; }
    }
    eccdRefreshOthers();
}
function eccdClearSiblingRow(i) {
    eccdClearRadios('sibling_sex[' + i + ']');
    eccdClearRadios('sibling_school[' + i + ']');
    var age = document.querySelector('input[name="sibling_age[' + i + ']"]');
    if (age) { age.value = ''; }
}
function eccdRefreshOthers() {
    var wraps = document.querySelectorAll('.eccd-other-wrap');
    for (var w = 0; w < wraps.length; w++) {
        var group = wraps[w].getAttribute('data-for');
        var show = false;
        var inputs = document.querySelectorAll('input');
        for (var i = 0; i < inputs.length; i++) {
            var el = inputs[i];
            if ((el.name === group || el.name === group + '[]') && el.checked && el.value === 'Others') {
                show = true;
            }
        }
        if (show) { wraps[w].classList.add('show'); } else { wraps[w].classList.remove('show'); }
    }
}
document.addEventListener('change', function (e) {
    if (e.target && e.target.tagName === 'INPUT') { eccdRefreshOthers(); }
});
document.addEventListener('DOMContentLoaded', eccdRefreshOthers);
</script>
JS;
}