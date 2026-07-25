<?php

declare(strict_types=1);

if (!function_exists('konvo_model_tiers')) {
    function konvo_model_tiers(): array
    {
        static $tiers = null;
        if (is_array($tiers)) {
            return $tiers;
        }

        $s = trim((string)getenv('MODEL_TIER_S'));
        $m = trim((string)getenv('MODEL_TIER_M'));
        $l = trim((string)getenv('MODEL_TIER_L'));

        $tiers = [
            's' => $s !== '' ? $s : 'claude-haiku-4-5',
            'm' => $m !== '' ? $m : 'claude-sonnet-5',
            'l' => $l !== '' ? $l : 'claude-opus-5',
        ];
        return $tiers;
    }
}

if (!function_exists('konvo_model_for_task')) {
    function konvo_model_for_task(string $task, array $ctx = []): string
    {
        $tiers = konvo_model_tiers();
        $technical = !empty($ctx['technical']);

        switch ($task) {
            case 'poll_pick':
            case 'quality_eval':
            case 'quality_rewrite':
            case 'article_title':
            case 'article_summary':
            case 'article_image_lead':
            case 'casual_topic':
            case 'reply_ack':
            // Classification / short-pick tasks: fast tier is sufficient and
            // keeps multi-call workers under the gateway timeout.
            case 'article_video_lead':
            case 'casual_topic_seed_pick':
            case 'low_effort_reaction':
            case 'topic_category':
            case 'topic_uniqueness':
                return $tiers['s'];

            case 'quality_hard':
            case 'reply_generation':
            case 'reply_rewrite':
            case 'technical_framework_rewrite':
            case 'code_repair':
            case 'deep_question':
                return $tiers['m'];

            case 'quality_rescue':
                return $technical ? $tiers['l'] : $tiers['m'];

            case 'reply_generation_technical':
                return $tiers['m'];

            default:
                return $technical ? $tiers['m'] : $tiers['m'];
        }
    }
}

