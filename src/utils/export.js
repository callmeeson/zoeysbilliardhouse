import * as XLSX from 'xlsx-js-style'

export const formatExcelDateTime = (dt) => {
  const d = new Date(dt)
  if (isNaN(d)) return ''
  const pad = (n) => String(n).padStart(2, '0')
  const h = d.getHours()
  const hour12 = h % 12 === 0 ? 12 : h % 12
  const ampm = h < 12 ? 'AM' : 'PM'
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(hour12)}:${pad(d.getMinutes())}:${pad(d.getSeconds())} ${ampm}`
}

// Row-highlight presets for admin-corrected transactions.
export const EXPORT_STYLES = {
  added: {
    fill: { fgColor: { rgb: 'E2EFDA' } },
    font: { color: { rgb: '375623' } },
  },
  edited: {
    fill: { fgColor: { rgb: 'FFF2CC' } },
    font: { color: { rgb: '9C6500' } },
  },
}

const applyRowStyles = (ws, rows, styles, headerLength) => {
  if (!styles) return
  for (let i = 0; i < rows.length; i++) {
    const style = styles[i]
    if (!style) continue
    const s = typeof style === 'string' ? EXPORT_STYLES[style] : style
    if (!s) continue
    for (let c = 0; c < headerLength; c++) {
      const addr = XLSX.utils.encode_cell({ r: i + 1, c })
      if (!ws[addr]) ws[addr] = { t: 'z', v: null }
      ws[addr].s = s
    }
  }
}

export const exportExcel = (filename, sheetName, headers, rows) => {
  const ws = XLSX.utils.aoa_to_sheet([headers, ...rows])
  const wb = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(wb, ws, sheetName)
  XLSX.writeFile(wb, filename, { cellStyles: true })
}

// rows = aoa data rows; styles[i] is null, an EXPORT_STYLES key, or a style object.
export const exportExcelHighlighted = (filename, sheetName, headers, rows, styles) => {
  const ws = XLSX.utils.aoa_to_sheet([headers, ...rows])
  applyRowStyles(ws, rows, styles, headers.length)
  const wb = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(wb, ws, sheetName)
  XLSX.writeFile(wb, filename, { cellStyles: true })
}

export const exportExcelWorkbook = (filename, sheets) => {
  const wb = XLSX.utils.book_new()
  for (const s of sheets) {
    const ws = XLSX.utils.aoa_to_sheet(s.aoa)
    if (s.styles) applyRowStyles(ws, s.aoa.slice(1), s.styles, s.aoa[0].length)
    XLSX.utils.book_append_sheet(wb, ws, s.name)
  }
  XLSX.writeFile(wb, filename, { cellStyles: true })
}