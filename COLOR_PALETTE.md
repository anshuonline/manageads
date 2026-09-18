# GanaTube Project — Official Design System & Color Palette

This document is the **single source of truth** for all AI agents, engineers, and contributors working on **GanaTube** (Frontend) and **ManageAds** (Backend/Admin).

---

## 🎨 Core Color Hierarchy

| Role | Color Name | Hex / RGBA Code | Tailwind / CSS Class | Description & Usage |
| :--- | :--- | :--- | :--- | :--- |
| **Primary Canvas** | **AMOLED Pure Black** | `#000000` | `bg-black`, `bg-[#000000]` | **Mandatory default background** for all screens, modals, and sheets. Saves battery on OLED/AMOLED screens. |
| **Surface / Card** | **AMOLED Deep Card** | `#0a0a0f` / `#0f0f17` | `bg-[#0a0a0f]`, `bg-white/[0.03]` | Slightly lifted dark surface for cards, panels, bottom sheets, and dropdowns. |
| **Card Borders** | **Subtle Glass Border** | `rgba(255, 255, 255, 0.08)` | `border-white/[0.08]` | Micro-borders to give structure without breaking the dark minimalist aesthetic. |
| **Hover Surface** | **Subtle Dark Hover** | `rgba(255, 255, 255, 0.05)` | `hover:bg-white/[0.05]` | Interactive hover state for list rows, dropdown items, and secondary buttons. |

---

## ✍️ Typography & Icon Colors

| Hierarchy | Color Value | Tailwind Class | Guidelines |
| :--- | :--- | :--- | :--- |
| **Primary Text** | `#ffffff` (Pure White) | `text-white` | All primary headings, song titles, active nav labels, and principal icons. |
| **Secondary Text** | `rgba(255, 255, 255, 0.7)` | `text-white/70`, `text-gray-300` | Artist names, descriptions, subheadings, and secondary labels. |
| **Muted / Dim Text** | `rgba(255, 255, 255, 0.4)` | `text-white/40`, `text-gray-500` | Timestamps, durations, metadata, inactive icons, and placeholders. |
| **Disabled Text** | `rgba(255, 255, 255, 0.2)` | `text-white/20`, `text-gray-600` | Disabled buttons, inactive states. |

---

## 🔮 Brand Gradient Accents (Purple & Pink)

GanaTube uses a signature vibrant **Purple-to-Pink gradient** for highlights, active tabs, primary action buttons, and subtle ambient glows.

```css
/* Signature Linear Gradient */
background: linear-gradient(135deg, #a855f7 0%, #ec4899 100%);

/* Tailwind Equivalent */
bg-gradient-to-r from-purple-500 to-pink-500
/* Or Darker Variant */
bg-gradient-to-r from-purple-600 to-pink-600
```

### Gradient Tokens:
- **Gradient Start (Purple)**: `#a855f7` (Tailwind `purple-500`) or `#9333ea` (Tailwind `purple-600`)
- **Gradient End (Pink)**: `#ec4899` (Tailwind `pink-500`) or `#db2777` (Tailwind `pink-600`)
- **Gradient Text**:
  ```html
  <span class="bg-gradient-to-r from-purple-400 via-pink-400 to-rose-400 bg-clip-text text-transparent">
    GanaTube
  </span>
  ```
- **Primary CTA Button**:
  ```html
  <button class="bg-gradient-to-r from-purple-600 to-pink-600 hover:from-purple-500 hover:to-pink-500 text-white font-bold px-6 py-3 rounded-xl shadow-lg shadow-purple-600/25 transition">
    Action
  </button>
  ```
- **Ambient Glows**:
  ```html
  <div class="w-96 h-96 bg-purple-600/10 rounded-full blur-[120px] pointer-events-none"></div>
  <div class="w-96 h-96 bg-pink-600/10 rounded-full blur-[120px] pointer-events-none"></div>
  ```

---

## 🚦 Functional & Status Colors

| Purpose | Color Hex | Tailwind Classes | Usage Example |
| :--- | :--- | :--- | :--- |
| **Danger / Delete (STRICT)** | `#ef4444` / `#f87171` | `bg-red-500/10 text-red-400 border border-red-500/20 hover:bg-red-500/20` | **Mandatory Red** for delete buttons, remove song, delete playlist, leave room. |
| **Success / Online** | `#10b981` / `#34d49a` | `bg-emerald-500/10 text-emerald-400 border border-emerald-500/20` | Connected status, active room bot, active ad placeholder, success toast. |
| **Warning / Coins** | `#eab308` / `#facc15` | `text-yellow-400`, `bg-yellow-500/10 text-yellow-400` | G-Coins, ratings stars, spin winner highlights, pending warnings. |
| **Info / Links** | `#38bdf8` / `#60a5fa` | `text-sky-400`, `hover:text-sky-300` | External documentation links, audio bitrates, secondary badges. |

---

## 🛡️ Zero-Tolerance Rules for Agents

1. **NO Light Mode / Gray Backgrounds**:
   - Never use `#ffffff`, `#f3f4f6`, `#1f2937` or standard gray backgrounds for page canvases. Always use **AMOLED `#000000`**.
2. **NO Random Color Clashing**:
   - Do not randomly use blue, green, or orange buttons for primary actions. The primary brand theme is **Purple & Pink Gradient** (`#a855f7` & `#ec4899`) with **White** text.
3. **Delete Buttons MUST be Red**:
   - As explicitly mandated by project rules, all delete/destructive buttons must be styled in red (`#ef4444`).
4. **Transparent & Blurred Overlays**:
   - Modals, bottom sheets, and dropdowns should use dark glassmorphism: `bg-black/90 backdrop-blur-xl border border-white/10`.
