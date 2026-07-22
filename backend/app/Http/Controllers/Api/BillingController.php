<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BillingController extends Controller
{
    public function plans(): JsonResponse
    {
        $month = (int) config('gpa.pro_price_rub_month', 199);
        $year = (int) config('gpa.pro_price_rub_year', 1490);
        $email = (string) config('gpa.billing_contact_email', 'ads@example.com');
        $freeLimit = (int) config('gpa.free_searches_per_day', 15);
        $guestLimit = (int) config('gpa.guest_searches_per_day', 5);

        return response()->json([
            'currency' => 'RUB',
            'note' => 'Лимиты защищают сервер: каждый поиск ходит в Steam, Plati и GGsel. Pro снимает дневной кап. Оплата картой — в следующем релизе; сейчас — промокод или заявка.',
            'billing_contact_email' => $email,
            'plans' => [
                [
                    'id' => 'guest',
                    'name' => 'Гость',
                    'price_rub' => 0,
                    'period' => null,
                    'searches_per_day' => $guestLimit,
                    'unlimited' => false,
                    'features' => [
                        "{$guestLimit} поисков в сутки",
                        'Без истории и избранного',
                    ],
                ],
                [
                    'id' => 'free',
                    'name' => 'Free',
                    'price_rub' => 0,
                    'period' => null,
                    'searches_per_day' => $freeLimit,
                    'unlimited' => false,
                    'features' => [
                        "{$freeLimit} поисков в сутки",
                        'История и избранное',
                        'Целевая цена',
                    ],
                ],
                [
                    'id' => 'pro_month',
                    'name' => 'Pro · месяц',
                    'price_rub' => $month,
                    'period' => 'month',
                    'searches_per_day' => null,
                    'unlimited' => true,
                    'features' => [
                        'Без дневного лимита поисков',
                        'Всё из Free',
                        'Приоритет при пиковой нагрузке (скоро)',
                    ],
                    'cta' => 'Оформить Pro',
                ],
                [
                    'id' => 'pro_year',
                    'name' => 'Pro · год',
                    'price_rub' => $year,
                    'period' => 'year',
                    'searches_per_day' => null,
                    'unlimited' => true,
                    'features' => [
                        'Без дневного лимита поисков',
                        'Выгоднее помесячной оплаты',
                        'Всё из Free',
                    ],
                    'cta' => 'Оформить Pro на год',
                ],
            ],
            'promo_hint' => 'Есть промокод? Активируйте в кабинете.',
            'checkout_status' => 'promo_and_manual',
            'checkout_message' => 'Автооплата ещё не подключена. Напишите на '.$email.' или введите промокод KEYSIGNAL-PRO.',
        ]);
    }

    public function activatePromo(Request $request): JsonResponse
    {
        $user = Auth::guard('sanctum')->user();
        if (! $user) {
            return response()->json(['detail' => 'Нужна авторизация'], 401);
        }

        $code = strtoupper(trim((string) $request->input('code', '')));
        if ($code === '') {
            return response()->json(['detail' => 'Введите промокод'], 422);
        }

        $map = $this->promoMap();
        if (! isset($map[$code])) {
            return response()->json(['detail' => 'Промокод не найден или уже недействителен'], 404);
        }

        $days = (int) $map[$code];
        $from = $user->hasActivePro() && $user->plan_expires_at
            ? $user->plan_expires_at->copy()
            : now();
        $user->plan = 'pro';
        $user->plan_expires_at = $from->copy()->addDays(max(1, $days));
        $user->save();

        return response()->json([
            'message' => "Pro активирован на {$days} дн.",
            'user' => $user->fresh()->toPublicArray(),
        ]);
    }

    public function requestCheckout(Request $request): JsonResponse
    {
        $user = Auth::guard('sanctum')->user();
        $planId = (string) $request->input('plan_id', 'pro_month');
        $email = (string) config('gpa.billing_contact_email', 'ads@example.com');

        return response()->json([
            'status' => 'pending_manual',
            'plan_id' => $planId,
            'message' => 'Оплата картой скоро. Напишите на '.$email.' с темой «KeySignal Pro»'
                .($user ? ' и email '.$user->email : ', или зарегистрируйтесь и активируйте промокод KEYSIGNAL-PRO.'),
            'mailto' => 'mailto:'.rawurlencode($email).'?subject='.rawurlencode('KeySignal Pro ('.$planId.')'),
        ], 202);
    }

    /** @return array<string, int> */
    private function promoMap(): array
    {
        $raw = (string) config('gpa.promo_codes', '');
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '' || ! str_contains($part, ':')) {
                continue;
            }
            [$c, $d] = array_pad(explode(':', $part, 2), 2, '30');
            $c = strtoupper(trim($c));
            $d = (int) trim($d);
            if ($c !== '' && $d > 0) {
                $out[$c] = $d;
            }
        }

        return $out;
    }
}
