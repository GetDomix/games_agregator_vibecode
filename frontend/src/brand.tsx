/** Brand: Игроскан — агрегатор цен на игры (Steam · Plati · GGsel) */
export const BRAND = {
  name: 'Игроскан',
  nameEn: 'Igroscan',
  tagline: 'агрегатор цен на игры',
  shortTagline: 'Steam · Plati · GGsel',
  description:
    'Игроскан — агрегатор цен на игры: сравниваем Steam RU, Plati.Market и GGsel. История, избранное, целевая цена.',
} as const

/** Animated mark: radar sweep on a graphite tile — beam + trail + blip (CSS-driven, see .brand-sweep/.brand-blip) */
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
      <rect x="0.5" y="0.5" width="47" height="47" rx="12" fill="#0d131b" stroke="#2a3a4c" />
      {/* radar rings — muted blue-grey, outermost carries a green tint */}
      <circle cx="24" cy="24" r="15" stroke="#52d992" strokeWidth="1" opacity="0.28" />
      <circle cx="24" cy="24" r="10" stroke="#4a5d72" strokeWidth="1" opacity="0.5" />
      <circle cx="24" cy="24" r="5" stroke="#4a5d72" strokeWidth="1" opacity="0.32" />
      {/* rotating sweep: soft trail wedges + beam line */}
      <g className="brand-sweep">
        <path d="M24 24 L24 9 A15 15 0 0 0 11.7 15.4 Z" fill="#52d992" opacity="0.1" />
        <path d="M24 24 L24 9 A15 15 0 0 0 17.7 10.4 Z" fill="#52d992" opacity="0.14" />
        <line x1="24" y1="24" x2="24" y2="9" stroke="#52d992" strokeWidth="1.8" strokeLinecap="round" />
      </g>
      {/* blip — pulses when the beam passes */}
      <circle className="brand-blip" cx="30.7" cy="17.3" r="2.1" fill="#52d992" />
      {/* center dot */}
      <circle cx="24" cy="24" r="1.9" fill="#52d992" />
    </svg>
  )
}
