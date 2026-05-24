<?php

function mapToInterventionCategory($status) {
    $status = trim($status);

    $mapping = [
        'Underweight' => 'Moderately Wasted',
        'Moderately Wasted' => 'Moderately Wasted',
        'Severely Underweight' => 'Severely Wasted',
        'Severely Wasted' => 'Severely Wasted',
        'Overweight' => 'Overweight',
        'Obese' => 'Obese'
    ];

    return isset($mapping[$status]) ? $mapping[$status] : null;
}

function getInterventionGuidanceRules($category) {
    $category = trim($category);

    $rules = [
        'Moderately Wasted' => [
            'Ensure the child eats 3 meals daily at the same time (no skipping)',
            'Give small but frequent meals if the child has a poor appetite',
            'Avoid distractions during meals (no gadgets or playing)',
            'Encourage daily active play to improve appetite',
            'Observe for signs of illness (fever, diarrhea, cough)'
        ],
        'Severely Wasted' => [
            'Ensure the child eats every meal on time (never skip meals)',
            'Give small but frequent meals throughout the day',
            'Ensure adequate water intake daily',
            'Monitor closely for weakness or signs of illness',
            'Seek immediate care if the child becomes very weak or condition worsens'
        ],
        'Overweight' => [
            'Limit sweet foods and sugary drinks (replace with water)',
            'Encourage daily active play (at least 30 minutes)',
            'Avoid extra servings (control portion size)',
            'Reduce screen time (TV, phone, tablet)',
            'Maintain regular meal times (no frequent snacking)'
        ],
        'Obese' => [
            'Avoid junk foods and sugary drinks (water only if possible)',
            'Ensure daily physical activity (play, walk, movement)',
            'Control food portions (no second servings)',
            'Limit screen time and avoid long sitting periods',
            'Maintain a consistent daily routine (meals, sleep, activity)'
        ]
    ];

    return isset($rules[$category]) ? $rules[$category] : [];
}

function buildGuidanceText($rules) {
    if (empty($rules) || !is_array($rules)) {
        return '';
    }

    return implode("\n", $rules);
}

function getInterventionSeverityRank($category) {
    $ranks = [
        'Moderately Wasted' => 1,
        'Severely Wasted' => 2,
        'Overweight' => 1,
        'Obese' => 2
    ];

    return isset($ranks[$category]) ? $ranks[$category] : 0;
}

function isSameInterventionGroup($category1, $category2) {
    $wasted_group = ['Moderately Wasted', 'Severely Wasted'];
    $overweight_group = ['Overweight', 'Obese'];

    if (in_array($category1, $wasted_group) && in_array($category2, $wasted_group)) {
        return true;
    }

    if (in_array($category1, $overweight_group) && in_array($category2, $overweight_group)) {
        return true;
    }

    return false;
}

function hasImprovedInterventionStatus($previous_category, $current_category) {
    if (empty($previous_category) || empty($current_category)) {
        return false;
    }

    if (!isSameInterventionGroup($previous_category, $current_category)) {
        return true;
    }

    $previous_rank = getInterventionSeverityRank($previous_category);
    $current_rank = getInterventionSeverityRank($current_category);

    return $current_rank < $previous_rank;
}

function checkNoImprovementForTwoMonths($records) {
    if (!is_array($records) || count($records) < 3) {
        return false;
    }

    usort($records, function ($a, $b) {
        return strtotime($a['date_recorded']) <=> strtotime($b['date_recorded']);
    });

    $records = array_values($records);
    $last_index = count($records) - 1;

    $baseline_category = $records[$last_index - 2]['intervention_category'];
    $month1_category = $records[$last_index - 1]['intervention_category'];
    $month2_category = $records[$last_index]['intervention_category'];

    $improved_month1 = hasImprovedInterventionStatus($baseline_category, $month1_category);
    $improved_month2 = hasImprovedInterventionStatus($month1_category, $month2_category);

    return (!$improved_month1 && !$improved_month2);
}

/* =========================================================
   NEW HELPER: Progress Status Checker
   Purpose:
   Compare previous category and current category.
========================================================= */
function getInterventionProgressStatus($previous_category, $current_category) {
    $previous_category = trim((string)$previous_category);
    $current_category = trim((string)$current_category);

    if (empty($previous_category) && empty($current_category)) {
        return 'no_data';
    }

    if (!empty($previous_category) && empty($current_category)) {
        return 'improved_to_normal';
    }

    if (empty($previous_category) && !empty($current_category)) {
        return 'new_at_risk';
    }

    if (!isSameInterventionGroup($previous_category, $current_category)) {
        return 'changed_risk_group';
    }

    $previous_rank = getInterventionSeverityRank($previous_category);
    $current_rank = getInterventionSeverityRank($current_category);

    if ($current_rank < $previous_rank) {
        return 'improved';
    }

    if ($current_rank === $previous_rank) {
        return 'no_improvement';
    }

    if ($current_rank > $previous_rank) {
        return 'worsened';
    }

    return 'no_data';
}

/* =========================================================
   NEW HELPER: Alert Type
   Purpose:
   Decide kung Nutritional Alert, Follow-up Reminder,
   or Final Follow-up Reminder.
========================================================= */
function getGuidanceAlertType($assessment_type, $progress_status, $current_category) {
    $assessment_type = strtolower(trim((string)$assessment_type));
    $progress_status = strtolower(trim((string)$progress_status));

    if (empty($current_category)) {
        return 'none';
    }

    if ($assessment_type === 'baseline') {
        return 'nutritional_alert';
    }

    if ($assessment_type === 'midline') {
        if (in_array($progress_status, ['no_improvement', 'worsened', 'new_at_risk', 'changed_risk_group'], true)) {
            return 'follow_up_reminder';
        }

        return 'nutritional_alert';
    }

    if ($assessment_type === 'endline') {
        if (in_array($progress_status, ['no_improvement', 'worsened', 'new_at_risk', 'changed_risk_group'], true)) {
            return 'final_follow_up_reminder';
        }

        return 'nutritional_alert';
    }

    if ($assessment_type === 'monthly_followup') {
        return 'nutritional_alert';
    }

    return 'nutritional_alert';
}

/* =========================================================
   NEW HELPER: Status Note
   Purpose:
   Text na mase-save sa intervention_guidance.status_note
========================================================= */
function buildGuidanceStatusNote($alert_type, $assessment_type, $progress_status) {
    $assessment_type = strtolower(trim((string)$assessment_type));
    $progress_status = strtolower(trim((string)$progress_status));

    if ($alert_type === 'nutritional_alert') {
        if ($assessment_type === 'baseline') {
            return 'Nutritional Alert: Child identified as at-risk based on Baseline assessment.';
        }

        if ($assessment_type === 'monthly_followup') {
            return 'Nutritional Alert: Child remains under monitoring based on Follow-up assessment.';
        }

        if ($assessment_type === 'midline') {
            return 'Nutritional Alert: Child is still under monitoring based on Midline assessment.';
        }

        if ($assessment_type === 'endline') {
            return 'Nutritional Alert: Child is still under monitoring based on Endline assessment.';
        }

        return 'Nutritional Alert: Child identified as at-risk from submitted report.';
    }

    if ($alert_type === 'follow_up_reminder') {
        if ($progress_status === 'new_at_risk') {
            return 'Follow-up Reminder: Child is identified as at-risk again based on Midline assessment.';
        }

        if ($progress_status === 'worsened') {
            return 'Follow-up Reminder: Child nutritional status worsened based on Midline assessment.';
        }

        if ($progress_status === 'no_improvement') {
            return 'Follow-up Reminder: Child showed no improvement based on Midline assessment.';
        }

        if ($progress_status === 'changed_risk_group') {
            return 'Follow-up Reminder: Child shifted to another at-risk category based on Midline assessment.';
        }

        return 'Follow-up Reminder: Child still needs nutritional attention based on Midline assessment.';
    }

    if ($alert_type === 'final_follow_up_reminder') {
        if ($progress_status === 'new_at_risk') {
            return 'Final Follow-up Reminder: Child is identified as at-risk again based on Endline assessment.';
        }

        if ($progress_status === 'worsened') {
            return 'Final Follow-up Reminder: Child nutritional status worsened based on Endline assessment.';
        }

        if ($progress_status === 'no_improvement') {
            return 'Final Follow-up Reminder: Child showed no improvement based on Endline assessment.';
        }

        if ($progress_status === 'changed_risk_group') {
            return 'Final Follow-up Reminder: Child shifted to another at-risk category based on Endline assessment.';
        }

        return 'Final Follow-up Reminder: Child still needs nutritional attention based on Endline assessment.';
    }

    return 'Generated from submitted monitoring report.';
}

/* =========================================================
   NEW HELPER: DB Flags
   Purpose:
   Convert alert type into intervention_guidance flags.
========================================================= */
function getGuidanceFlags($alert_type) {
    $flags = [
        'is_at_risk' => 0,
        'needs_counseling' => 0,
        'needs_referral' => 0
    ];

    if ($alert_type === 'nutritional_alert') {
        $flags['is_at_risk'] = 1;
        $flags['needs_counseling'] = 0;
        $flags['needs_referral'] = 0;
    }

    if ($alert_type === 'follow_up_reminder') {
        $flags['is_at_risk'] = 1;
        $flags['needs_counseling'] = 1;
        $flags['needs_referral'] = 0;
    }

    if ($alert_type === 'final_follow_up_reminder') {
        $flags['is_at_risk'] = 1;
        $flags['needs_counseling'] = 1;
        $flags['needs_referral'] = 1;
    }

    return $flags;
}
