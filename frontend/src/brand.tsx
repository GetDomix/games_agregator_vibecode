/** Brand: Игроскан — агрегатор цен на игры (Steam · Plati · GGsel) */
export const BRAND = {
  name: 'Игроскан',
  nameEn: 'Igroscan',
  tagline: 'агрегатор цен на игры',
  shortTagline: 'Steam · Plati · GGsel',
  description:
    'Игроскан — агрегатор цен на игры: сравниваем Steam RU, Plati.Market и GGsel. История, избранное, целевая цена.',
} as const

/** Geometric mark: scan arc + game diamond */
export function BrandMark({ className = '', size = 42 }: { className?: string; size?: number }) {
  return (
    <svg
      className={className}
      width={size}
      height={size}
      viewBox="0 0 48 48"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      aria-hidden
    >
      <defs>
        <linearGradient id="ig-g" x1="8" y1="6" x2="42" y2="44" gradientUnits="userSpaceOnUse">
          <stop stopColor="#ff6b4a" />
          <stop offset="0.55" stopColor="#f59e0b" />
          <stop offset="1" stopColor="#22c55e" />
        </linearGradient>
      </defs>
      <rect x="2" y="2" width="44" height="44" rx="14" fill="url(#ig-g)" />
      {/* scan rings */}
      <path
        d="M14 28c0-5.5 4.5-10 10-10s10 4.5 10 10"
        stroke="#fff"
        strokeWidth="2.2"
        strokeLinecap="round"
        opacity="0.95"
      />
      <path
        d="M18.5 28c0-3 2.5-5.5 5.5-5.5s5.5 2.5 5.5 5.5"
        stroke="#fff"
        strokeWidth="2.2"
        strokeLinecap="round"
        opacity="0.75"
      />
      <circle cx="24" cy="28" r="2.4" fill="#fff" />
      {/* soft pulse dot */}
      <circle cx="34" cy="16" r="3" fill="#fff" opacity="0.9" />
    </svg>
  )
}
