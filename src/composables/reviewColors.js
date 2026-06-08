// Paleta cerrada de colores para los estados de revisión. Las clases Tailwind deben
// aparecer LITERALES aquí (Tailwind v4 purga las clases construidas dinámicamente),
// por eso se enumeran en mapas en vez de interpolar `bg-${color}-100`.
//
// Mantener sincronizado con ReviewStatus::COLORS (api/lib/ReviewStatus.php).

export const REVIEW_COLORS = [
  'slate', 'gray', 'red', 'orange', 'amber', 'yellow', 'green', 'emerald',
  'teal', 'sky', 'blue', 'indigo', 'violet', 'purple', 'pink', 'rose',
]

// Píldora del badge (fondo claro + texto).
const BADGE = {
  slate: 'bg-slate-100 text-slate-700',
  gray: 'bg-gray-100 text-gray-700',
  red: 'bg-red-100 text-red-700',
  orange: 'bg-orange-100 text-orange-700',
  amber: 'bg-amber-100 text-amber-700',
  yellow: 'bg-yellow-100 text-yellow-700',
  green: 'bg-green-100 text-green-700',
  emerald: 'bg-emerald-100 text-emerald-700',
  teal: 'bg-teal-100 text-teal-700',
  sky: 'bg-sky-100 text-sky-700',
  blue: 'bg-blue-100 text-blue-700',
  indigo: 'bg-indigo-100 text-indigo-700',
  violet: 'bg-violet-100 text-violet-700',
  purple: 'bg-purple-100 text-purple-700',
  pink: 'bg-pink-100 text-pink-700',
  rose: 'bg-rose-100 text-rose-700',
}

// Botón de acción de revisión (fondo sólido + texto blanco + hover).
const BTN = {
  slate: 'bg-slate-600 hover:bg-slate-700 text-white',
  gray: 'bg-gray-600 hover:bg-gray-700 text-white',
  red: 'bg-red-600 hover:bg-red-700 text-white',
  orange: 'bg-orange-600 hover:bg-orange-700 text-white',
  amber: 'bg-amber-600 hover:bg-amber-700 text-white',
  yellow: 'bg-yellow-500 hover:bg-yellow-600 text-white',
  green: 'bg-green-600 hover:bg-green-700 text-white',
  emerald: 'bg-emerald-600 hover:bg-emerald-700 text-white',
  teal: 'bg-teal-600 hover:bg-teal-700 text-white',
  sky: 'bg-sky-600 hover:bg-sky-700 text-white',
  blue: 'bg-blue-600 hover:bg-blue-700 text-white',
  indigo: 'bg-indigo-600 hover:bg-indigo-700 text-white',
  violet: 'bg-violet-600 hover:bg-violet-700 text-white',
  purple: 'bg-purple-600 hover:bg-purple-700 text-white',
  pink: 'bg-pink-600 hover:bg-pink-700 text-white',
  rose: 'bg-rose-600 hover:bg-rose-700 text-white',
}

// Hex de la sombra 600 (para Chart.js, que no entiende clases Tailwind).
const HEX = {
  slate: '#475569', gray: '#4b5563', red: '#dc2626', orange: '#ea580c',
  amber: '#d97706', yellow: '#ca8a04', green: '#16a34a', emerald: '#059669',
  teal: '#0d9488', sky: '#0284c7', blue: '#2563eb', indigo: '#4f46e5',
  violet: '#7c3aed', purple: '#9333ea', pink: '#db2777', rose: '#e11d48',
}

export const badgeClass = (color) => BADGE[color] ?? BADGE.slate
export const btnClass = (color) => BTN[color] ?? BTN.slate
export const colorHex = (color) => HEX[color] ?? HEX.slate
