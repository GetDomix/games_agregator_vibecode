<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AdsController extends Controller
{
    public function config(): JsonResponse
    {
        $enabled = (bool) config('gpa.ads_enabled', true);
        $email = (string) config('gpa.ads_contact_email', 'ads@example.com');
        $label = (string) config('gpa.ads_label', 'Реклама');
        $slots = [];
        if ($enabled) {
            $defs = [
                ['id' => 'after_results_billboard', 'placement' => 'after_results', 'format' => 'billboard', 'size_hint' => '970×250', 'title' => 'Реклама · после результатов', 'subtitle' => 'Отдельный блок после полного списка предложений.'],
                ['id' => 'footer_leaderboard', 'placement' => 'footer', 'format' => 'leaderboard', 'size_hint' => '728×90', 'title' => 'Реклама · подвал', 'subtitle' => 'Нижний баннер.'],
            ];
            foreach ($defs as $d) {
                $slots[] = array_merge($d, [
                    'cta' => 'Связаться',
                    'provider' => 'placeholder',
                    'html' => null,
                    'image_url' => null,
                    'click_url' => 'mailto:'.rawurlencode($email).'?subject='.rawurlencode('Реклама ('.$d['id'].')'),
                ]);
            }
        }

        return response()->json([
            'enabled' => $enabled,
            'contact_email' => $email,
            'label' => $label,
            'note' => 'Реклама не влияет на состав и порядок предложений. Размещение: '.$email,
            'slots' => $slots,
        ]);
    }
}
