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
                ['id' => 'header_leaderboard', 'placement' => 'header', 'format' => 'leaderboard', 'size_hint' => '728×90', 'title' => 'Реклама · шапка', 'subtitle' => 'Место для партнёров и спецпредложений.'],
                ['id' => 'mid_billboard', 'placement' => 'mid', 'format' => 'billboard', 'size_hint' => '970×250', 'title' => 'Реклама · центр', 'subtitle' => 'Широкий баннер между поиском и результатами.'],
                ['id' => 'results_inline', 'placement' => 'inline_results', 'format' => 'rectangle', 'size_hint' => '300×250', 'title' => 'Реклама · в результатах', 'subtitle' => 'Блок после карточки Steam.'],
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
            'note' => 'Рекламные места на сайте. По вопросам размещения: '.$email,
            'slots' => $slots,
        ]);
    }
}
