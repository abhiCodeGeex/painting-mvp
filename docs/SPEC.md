# Paint MVP — Artwork Quotation & Approval Platform

**Spec version:** 1.1 (draft)  ·  **Date:** 2026-11-16  ·  **Status:** For review

> **Decisions (v1.1):** Language **English only** (no translations in MVP). Currency **INR (₹)**, single currency, Indian number grouping (₹1,23,456.00). Tax = **GST**: CGST + SGST when the delivery state equals the company's state, otherwise IGST (default 18%, configurable per tax master). Customer tax identifiers: **GSTIN** (companies, optional) and PAN (optional).

---

## 1. Overview

A web platform where customers (homeowners, interior designers, hotels, offices) request artwork — catalog paintings, new custom creations, or framing of their own artwork — configure size, medium, quality and framing, preview it on a wall in 3D, and receive a formal quotation they can approve, request changes on, or reject.

The admin side manages all masters (artworks, frames, mats, glazing, etc.), pricing rules, customers, enquiries and quotes.

### 1.1 Goals
- Replace email/WhatsApp back-and-forth with one structured enquiry → quote → approval flow.
- Make quotes fast and consistent through a rule-based pricing engine.
- Reduce misunderstandings with a true-to-scale 3D wall preview attached to the quote.
- Give a full, auditable approval record.

### 1.2 Scope of this MVP
| In scope | Out of scope (later phases) |
|---|---|
| Masters (CRUD) & settings | Deposits, online payments |
| Customer registration & portal | Invoices |
| Enquiry with multiple line items | Proof / mockup revision cycle after approval |
| Clarification message thread | Production, QC, shipping tracking |
| Pricing engine + indicative estimate | Supplier / artist portal |
| 3D virtual-room wall preview + snapshot | Upload-own-wall-photo preview, AR |
| Quote with versions, PDF, internal discount approval | Multi-approver customer teams |
| Customer Approve / Request changes / Reject | Inventory of mouldings |
| Expiry, reminders, email notifications | WhatsApp notifications |
| Audit log, basic dashboard | Advanced reporting |

---

## 2. Users & Roles

| Role | Panel | Can do |
|---|---|---|
| **Super Admin** | Admin | Everything, incl. settings, users, roles |
| **Manager** | Admin | Everything a Sales user can + approve quotes needing internal approval, view margins, reports |
| **Sales / Estimator** | Admin | Manage customers, enquiries, quotes assigned to them (or all, by permission); view cost/margin (permission-controlled); read masters |
| **Catalog Manager** | Admin | CRUD on masters & pricing rules |
| **Customer** | Customer portal | Create/view own enquiries, use preview, message, view and respond to own quotes |
| **Quote link holder** (no login) | Public signed page | View one quote and approve / request changes / reject it |

Permissions are granular (per resource, per action) via `spatie/laravel-permission` + Filament Shield, so roles above are defaults, not hard-coded.

---

## 3. End-to-End Flow

```
Customer registers / Admin creates customer
        │
        ▼
Enquiry: Draft ──submit──▶ Submitted ──assign──▶ Under review
                                                   │   ▲
                                   ask question ───┘   └── customer replies
                                   (Needs clarification)
                                                   │
                                                   ▼
                                     Quote v1 drafted by Sales
                                                   │
                         discount/margin outside limits? ──yes──▶ Pending internal approval
                                                   │                    │ Manager approves / returns
                                                   ▼                    ▼
                                              Quote Sent (email + signed link)
                                                   │
                     ┌─────────────────┬───────────┴──────────┬──────────────────┐
                     ▼                 ▼                      ▼                  ▼
                 Approved      Changes requested           Rejected          Expired
                 (end MVP)     → Quote v2 (v1 superseded)  (reason req.)     (re-quote request)
```

### 3.1 Enquiry statuses
| Status | Meaning | Set by |
|---|---|---|
| `draft` | Being filled by customer/admin, not visible to sales queue | Customer / Admin |
| `submitted` | Sent, waiting for assignment | Customer / Admin |
| `under_review` | Assigned sales user working on it | Admin |
| `needs_clarification` | Waiting on customer reply | Admin |
| `quoted` | At least one quote sent, awaiting response | System |
| `changes_requested` | Customer asked for changes on latest quote | System |
| `approved` | Latest quote approved | System |
| `rejected` | Latest quote rejected | System |
| `expired` | Latest quote expired without response | System (scheduler) |
| `cancelled` | Withdrawn by customer or admin before quote | Customer / Admin |

### 3.2 Quote statuses
| Status | Meaning |
|---|---|
| `draft` | Being prepared |
| `pending_internal_approval` | Discount/margin outside limits, waiting for Manager |
| `ready` | Internally OK, not yet sent |
| `sent` | Emailed to customer |
| `viewed` | Customer opened it (first view timestamp) |
| `approved` | Customer approved |
| `changes_requested` | Customer requested changes |
| `rejected` | Customer rejected |
| `expired` | Validity date passed |
| `superseded` | A newer version was sent |
| `withdrawn` | Cancelled by admin after sending |

### 3.3 Transition rules
- All transitions go through one service (`EnquiryWorkflow`, `QuoteWorkflow`); controllers never set status directly.
- Every transition writes an `status_histories` row (from, to, user, reason, timestamp).
- Only one quote version per enquiry may be in `sent`/`viewed` at a time. Sending v2 sets v1 → `superseded`.
- `approved`, `rejected`, `expired`, `superseded`, `withdrawn` are terminal for that version.
- An expired quote cannot be approved; customer sees a **Request re-quote** button that moves the enquiry back to `under_review`.
- A Manager can **reopen** a rejected/expired enquiry (creates next quote version).

---

## 4. Masters (Admin CRUD)

### 4.1 Common rules for every master
- Fields `is_active` (bool), `sort_order` (int), `created_by`, `updated_by`, timestamps.
- **No hard delete** if referenced; use soft delete / deactivate. Inactive items are hidden from the customer form but still display on old enquiries/quotes.
- Text fields in English only (no translation layer in MVP).
- Image(s) via media library with auto thumbnails.
- Every change is audit-logged.

### 4.2 Master list & fields

**Artists**
- name, bio, photo, country, is_active

**Artwork categories / styles**
- name, slug, parent (optional tree)

**Artworks (catalog)**
- code (unique), title, artist, categories (many), style tags
- description, images (main + gallery)
- type: `original` | `print_open_edition` | `print_limited_edition`
- edition_size (limited only), editions_sold (info)
- default medium, allowed mediums (many), allowed substrates (many)
- native aspect ratio (w:h), min size, max size (mm)
- allow_custom_size (bool), allowed standard sizes (many)
- pricing_model: `fixed_per_size` | `per_m2`
- price_per_m2 (if per_m2), size prices table (if fixed_per_size)
- lead_time_days
- is_featured, is_active

**Mediums / techniques**
- name (oil, acrylic, watercolour, mixed media, giclée print, photo print…)
- kind: `hand_painted` | `print`
- price_factor (multiplier, default 1.00)
- allowed substrates (many)
- min_resolution_ppi (prints only, e.g. 150) — used for upload warnings

**Substrates**
- name (stretched canvas, canvas roll, fine-art paper, wood panel, aluminium dibond…)
- depth_mm (e.g. 20, 40 for gallery wrap; 0 for paper)
- edge_styles allowed: `wrapped_image` | `mirrored` | `solid_colour`
- allows_glazing (bool), allows_mat (bool), requires_frame (bool)
- cost_per_m2, cost_per_perimeter_m (stretcher bars)
- max_width_mm, max_height_mm

**Frames (mouldings)**
- code (unique), name, supplier_code
- profile_width_mm (face width), profile_depth_mm, rebate_depth_mm
- colour_hex, finish: `matte` | `satin` | `gloss` | `metallic`
- material: wood | aluminium | other
- texture image (optional, for 3D wood grain), corner photo, profile photo
- price_per_m (cost), waste_percent (default 10%)
- min_inner_width/height_mm, max_inner_width/height_mm, max_perimeter_mm
- suitable substrates (many), is_floater (bool, for canvas float frames)
- is_active

**Mats / passe-partout**
- code, name, colour_hex, core/bevel_colour_hex
- thickness_mm (e.g. 1.4, 2.0)
- default_border_mm, bottom_weight_mm (extra bottom border, optional)
- cost_per_m2, cut_fee_per_piece
- max_sheet_width/height_mm

**Glazing**
- name (none, float glass, acrylic, UV glass, museum glass, anti-reflective acrylic)
- material: `none` | `glass` | `acrylic`
- reflection_level: `none` | `standard` | `low` (used in 3D)
- cost_per_m2
- max_width_mm, max_height_mm, max_area_m2

**Finishing & hardware**
- name (varnish, D-rings + wire, French cleat, security hangers, dust cover…)
- pricing: `fixed` | `per_m2` | `per_perimeter_m`, cost
- applies to substrates (many), default (bool)

**Quality tiers**
- name (Standard, Premium, Museum), description
- price_factor
- default medium / substrate / glazing / mat (optional presets)

**Standard sizes**
- label (e.g. "50 × 70 cm", "A2"), width_mm, height_mm, orientation-agnostic (bool)

**Wall colours** (for 3D preview)
- name, hex, paint brand + code (optional), sort_order

**Room scenes** (for 3D preview)
- name (living room, bedroom, hotel corridor, office, dining)
- scale object: `sofa` | `bed` | `door` | `console` | `none`
- default_wall_width_mm, default_wall_height_mm, floor type (wood, carpet, tile)
- preview thumbnail

**Pricing rules** (single settings-like master, versioned)
- labour_fixed_per_piece, labour_per_perimeter_m
- default_markup_percent (cost → sell)
- quantity breaks: list of {min_qty, discount_percent}
- rush_fee_percent, rush_threshold_days (deadline closer than N days)
- rounding: `none` | `0.01` | `1` | `5`
- estimate_range_percent (e.g. ±15%, for indicative estimate)

**Taxes (GST)**
- name (e.g. "GST 18%", "GST 12%"), rate_percent, hsn_sac_code (optional), is_default
- Split rule applied at quote time: intra-state → CGST = SGST = rate/2; inter-state → IGST = rate

**Currency**
- INR only in MVP (setting, not a master). Amounts formatted with Indian grouping.

**Extra charges** (quote extras)
- name (packing standard, wooden crate, shipping zone A, installation per piece…)
- pricing: `fixed` | `per_piece` | `per_m2`, cost

**Enquiry sources**
- name (website, phone, walk-in/showroom, email, referral, interior designer)

**Rejection reasons**
- name (price too high, lead time, design not suitable, went with competitor, project cancelled, other)

**Email templates**
- key (event), subject + body, placeholders documented, is_active

### 4.3 Compatibility rules (validation)
Enforced in both the customer form (live) and server-side on submit and on quote line save:

1. Medium must be allowed for the artwork and for the substrate.
2. Size within artwork min/max and substrate max.
3. Frame inner size within frame min/max; perimeter ≤ frame max_perimeter.
4. If substrate `allows_glazing = false` → glazing forced to *none*.
5. If substrate `allows_mat = false` → no mat.
6. Glazing size/area within glazing max; otherwise suggest acrylic.
7. Frame must be in substrate's suitable list (e.g. floater frames only for stretched canvas).
8. Print resolution: uploaded image px ÷ (size in inches) < medium.min_resolution_ppi → **warning** (not block).
9. Limited edition: quantity ≤ remaining editions → warning to sales.

Violations return a clear message, e.g. *"Glass is not available above 100 × 150 cm. Acrylic has been selected instead."*

---

## 5. Customer Accounts

### 5.1 Registration
- Fields: customer type (`individual` | `company`), first name, last name, email, phone (with country code, default +91), password.
- Company only: company name, GSTIN (optional, validated 15-char format), PAN (optional), website.
- Consents: accept Terms (required), accept Privacy Policy (required), marketing emails (optional, unticked).
- Email verification required before submitting an enquiry (draft allowed).

### 5.2 Profile
- Billing address (street, city, PIN code, state — from Indian states list with GST state code, country default India)
- Delivery addresses (multiple, one default; label, contact name, phone, full address, access notes e.g. "no lift, 3rd floor")
- Tax-exempt flag (admin-only, e.g. SEZ / export) + tax exemption note

### 5.3 Admin-created customers
- Sales can create a customer (phone/showroom lead). System sends an invitation email to set password. Enquiries can be created on their behalf before they accept.

### 5.4 GDPR
- Customer can download their data (JSON/PDF) and request deletion (admin action; anonymises personal data, keeps quote amounts for accounting).
- Consent timestamps stored.

---

## 6. Enquiry

### 6.1 Enquiry header
| Field | Type | Required | Notes |
|---|---|---|---|
| Reference | auto | — | `ENQ-2026-0001` |
| Customer | relation | yes | auto for customer; selectable for admin |
| Project name | text | yes | e.g. "Hotel Lumière — Lobby" |
| Project type | enum | yes | home, hotel, office, restaurant, retail, other |
| Source | relation | admin only | Enquiry sources master; website by default |
| Delivery address | relation | yes | from profile, or add new |
| City / country of installation | derived | — | from delivery address |
| Required-by date | date | no | triggers rush fee rule |
| Budget range | enum | no | <1k, 1–5k, 5–20k, 20k+, not sure |
| Installation needed | bool | no | quoted as extra line |
| Preferred contact | enum | no | email, phone, WhatsApp |
| General notes | long text | no | |
| General attachments | files | no | floor plans, mood boards (PDF, JPG, PNG) |
| Assigned to | user | admin only | |
| Priority | enum | admin only | low, normal, high |
| Internal notes | long text | admin only | never shown to customer |

### 6.2 Line items (1..n per enquiry)
Customer can **add, duplicate, reorder, delete** lines. Each line:

**A. Source** (`source_type`)
1. `catalog` — select an artwork from the catalog (searchable, filter by artist/style/colour).
2. `custom_creation` — new artwork to be created.
3. `own_artwork` — customer supplies artwork for framing/reproduction.

**B. Fields by source**

*Catalog:*
- artwork (required); edition type shown (original / print).

*Custom creation (prototype brief):*
- title / working name
- subject & description (long text, required)
- style (select from categories, + "other")
- mood keywords (tags)
- colour palette: up to 6 colours (hex picker) + "match my wall colour" option
- reference images / sketches (files, up to 10, required min 1)
- number of concept revisions included (default from settings, e.g. 2) — read-only to customer
- usage rights: `private display` | `commercial display` | `reproduction rights needed`

*Own artwork:*
- service: `frame_only` | `reproduce_and_frame`
- photo of the artwork (required), condition notes
- for reproduce: high-res file upload (TIFF/PNG/JPG/PDF up to 200 MB)
- **rights confirmation** checkbox (required): "I own the rights to this image or have permission to reproduce it."

**C. Configuration (all sources)**
| Field | Notes |
|---|---|
| Unit | cm / inch toggle (stored internally in **mm**) |
| Size refers to | `image size` \| `outer frame size` (required, with diagram tooltip) |
| Width, Height | or pick a standard size; aspect-ratio lock for catalog artworks (with "crop allowed" option) |
| Orientation | portrait / landscape / square (auto from W×H) |
| Quantity | integer ≥ 1 |
| Quality tier | sets defaults below |
| Medium | filtered by compatibility |
| Substrate | filtered |
| Edge style | if substrate has depth |
| Frame | visual picker (corner photo, colour, width) or "No frame" or "Advise me" |
| Mat | picker or none; border width (default from mat) |
| Glazing | filtered by substrate & size |
| Finishing / hanging | multi-select, defaults pre-ticked |
| Room / placement | free text, e.g. "Room type A, above bed" |
| Wall width/height | optional (used by 3D preview) |
| Line notes | text |

**D. Calculated & shown live**
- Image size, mat window, mat outer, **outer frame size** (whichever wasn't entered is derived).
- Indicative price range per unit and line (if enabled in settings), labelled *"Indicative estimate — final price in your quotation."*
- Compatibility warnings.
- Mini 2D framed preview + **Open 3D preview** button.

**E. 3D snapshots**
- From the preview the customer can save up to 3 snapshots per line; stored with the line and shown to sales and on the quote.

### 6.3 Enquiry submission
- Validates all lines; on success: status `submitted`, reference assigned, confirmation email to customer, notification to sales/admins.
- After submit, the customer cannot edit lines; changes go through the message thread or "Request changes" on a quote. Admin can edit (audit-logged).
- Customer can **cancel** an enquiry before a quote is sent.

### 6.4 Clarification thread
- One thread per enquiry; messages with attachments (max 10 files, 25 MB each).
- Message types: customer message, admin message, **admin question** (switches enquiry to `needs_clarification`), system event.
- Customer reply to a question returns enquiry to `under_review`.
- Internal notes are separate and never appear in the thread.
- Email notification on new message (batched: max one email per 10 minutes per recipient).

---

## 7. Pricing Engine

A pure, unit-tested service (`PricingEngine`) used for both the customer estimate and the admin quote. It takes a line configuration + the current masters/rules and returns a **breakdown**.

### 7.1 Dimensions (all in mm)
```
If size_ref = image:
    image_w, image_h = input
If size_ref = outer:
    image_w = outer_w − 2·frame.profile_width − 2·mat_border (+ overlap)
    (same for h; mat bottom weight applied to h)

mat_window   = image − 2·overlap            (overlap default 3 mm per side)
mat_outer    = image + 2·mat_border  (+ bottom_weight on h)
rebate_inner = (mat_outer or image or substrate) + 2·rebate_allowance (default 2 mm)
outer_frame  = rebate_inner + 2·frame.profile_width
perimeter    = 2·(rebate_inner_w + rebate_inner_h)
```

### 7.2 Cost components (per unit)
| Component | Formula |
|---|---|
| Artwork | catalog fixed size price, **or** area_m² × price_per_m² × medium.price_factor × quality.price_factor; custom creation: area_m² × medium base + **manual complexity amount** entered by sales; own artwork: 0 (reproduction = print cost per m²) |
| Substrate | area_m² × cost_per_m² + perimeter_m × cost_per_perimeter_m |
| Frame | ((perimeter + 8·profile_width) / 1000) × (1 + waste%) × price_per_m |
| Mat | mat_outer_area_m² × cost_per_m² + cut_fee |
| Glazing | rebate_area_m² × cost_per_m² |
| Finishing / hardware | per item pricing type |
| Labour | labour_fixed + perimeter_m × labour_per_perimeter_m |

```
unit_cost      = Σ components (cost)
unit_sell      = catalog sell price parts as-is + non-catalog parts × (1 + markup%)
qty_discount   = from quantity breaks (by line quantity)
rush_fee       = if required_by − today < rush_threshold_days → rush_fee%
unit_price     = round( unit_sell × (1 − qty_discount) × (1 + rush_fee) )
line_subtotal  = unit_price × qty
line_discount  = manual % or amount (sales)
line_total     = line_subtotal − line_discount
```

### 7.3 Quote totals
```
items_total    = Σ line_total
extras_total   = Σ extras where inclusion = "charged"
quote_discount = manual % or amount on (items_total + extras_total)
taxable        = items_total + extras_total − quote_discount
tax            = taxable × tax_rate   (0 if customer tax-exempt)
                 intra-state: CGST = SGST = tax / 2 ; inter-state: IGST = tax
                 (place of supply = delivery address state vs company state)
grand_total    = taxable + tax
margin%        = (taxable − total_cost) / taxable
```

### 7.4 Rules
- All money stored as **integer paise** (INR × 100); displayed with Indian grouping (₹1,23,456.00).
- Indicative estimate = unit_price ± estimate_range_percent, shown as a range; hidden entirely if the setting is off or line is `custom_creation` with no complexity amount.
- Every computed breakdown is stored as JSON on the quote line (**snapshot**). Master price changes never alter existing quotes.
- Sales can override any unit price; override is flagged and audit-logged with a reason.

---

## 8. Quotation

### 8.1 Creation
- Created from an enquiry ("Create quote"), pre-filled from enquiry lines via the pricing engine.
- Sales can edit lines, add lines not in the enquiry, mark lines as **optional** (customer can tick/untick when approving), and add extras.

### 8.2 Quote header fields
| Field | Notes |
|---|---|
| Number & version | `Q-2026-0001` + `v1`, `v2`… (number fixed per enquiry, version increments) |
| Issue date | auto on send |
| Valid until | default issue + N days (settings, e.g. 30) |
| Place of supply | delivery state (decides CGST+SGST vs IGST) |
| Customer, billing address, delivery address | snapshot (copied, not linked) |
| Customer GSTIN | snapshot |
| Lead time | text/days, e.g. "4–6 weeks after approval" |
| Payment terms | text, default from settings, e.g. "50% deposit, balance before delivery" |
| Inclusions / exclusions | each extra marked `included` / `charged` / `estimated` / `excluded` |
| Terms & conditions | default from settings, editable per quote |
| Note to customer | shown on quote & PDF |
| Internal note | admin only |
| Prepared by | sales user (name + email on PDF) |

### 8.3 Quote line fields (snapshot)
- line no., title, image (artwork / uploaded / 3D snapshot)
- full spec text (auto-generated: size, medium, substrate, edge, frame, mat, glazing, finishing)
- quantity, unit price, line discount, line total
- is_optional, is_selected (customer choice for optional lines)
- breakdown JSON (cost & sell per component) — admin only
- override flag + reason

### 8.4 Internal approval
- Triggered if **any** of: quote discount > `max_discount_percent` (setting), margin < `min_margin_percent`, any price override, grand total > `approval_threshold_amount`.
- Status → `pending_internal_approval`; Managers notified.
- Manager **approves** (→ `ready`) or **returns** with comment (→ `draft`).
- Sales cannot send a quote that needs approval until approved.

### 8.5 Sending
- "Send" generates the PDF, sets issue date / valid until, status `sent`, emails customer with:
  - summary, total, validity date, PDF attached,
  - **signed link** to the quote page (no login needed), valid until quote expiry + 30 days (read-only after expiry).
- Quote also visible in the customer portal.
- First open of the link/page sets `viewed` + timestamp.

### 8.6 Revisions
- "Create new version" copies the latest version into a new `draft` with version+1.
- When the new version is sent, the previous `sent`/`viewed`/`changes_requested` version becomes `superseded`.
- Customers always see all versions (latest highlighted, older marked superseded).
- Version comparison view for admin (changed fields highlighted).

### 8.7 PDF
- Branded: logo, company details, GSTIN, bank details (settings).
- Header block, customer block, line table with thumbnail & spec, optional lines section, extras, totals, tax, payment terms, lead time, T&C, validity, "Visual previews are indicative; colours and textures may vary."
- English; GST breakdown (CGST/SGST or IGST) shown in totals.
- Rendered with `spatie/laravel-pdf` + Gotenberg; stored per version (immutable once sent).

---

## 9. Customer Response

Available on the portal quote page and on the signed-link page. Only for the **latest** version in `sent`/`viewed`.

### 9.1 Approve
- Tick/untick optional lines (totals recalculate live).
- Required: full name, job title/role (company customers), "I accept the Terms & Conditions" checkbox.
- Optional: PO number, PO file upload.
- On submit store: name, title, email (if logged in), IP, user-agent, timestamp, selected optional lines, final grand total, PO data.
- Quote → `approved`; enquiry → `approved`; confirmation emails to customer & sales; approved PDF re-rendered with an "Approved on … by …" stamp.

### 9.2 Request changes
- Required: comment (what to change); optional attachments; optional per-line comments.
- Quote → `changes_requested`; enquiry → `changes_requested`; sales notified.

### 9.3 Reject
- Required: reason (rejection reasons master); optional comment.
- Quote → `rejected`; enquiry → `rejected`; sales notified.

### 9.4 Expiry
- Daily scheduler: `sent`/`viewed` quotes past valid_until → `expired`; enquiry → `expired`.
- Reminder email N days before expiry (setting, default 3) if not responded.
- Expired page shows **Request re-quote** (creates a message + enquiry → `under_review`).

---

## 10. 3D Wall Preview (MVP: Level 2 + Level 1 fallback)

### 10.1 Functional
- Opens in a modal/full-screen from an enquiry line (and read-only from a quote line).
- Controls: room scene, wall colour (palette + free picker), wall size (default from scene or line), picture light on/off, show dimensions on/off, hanging height (default artwork centre 145 cm).
- Shows the line's artwork image (catalog image, uploaded file, or for custom creation the first reference image with a "concept placeholder" label) at **true scale**.
- 3D frame built from frame master: profile width/depth, colour, finish (roughness/metalness), optional texture.
- Mat with window and visible bevel; glazing reflection per reflection level; substrate depth with edge style.
- Scale object (sofa/bed/door) from room scene.
- Camera: limited orbit (±35° horizontal, ±15° vertical) and zoom.
- **Multi-piece wall**: option to show all lines of the enquiry on one wall, draggable, with spacing guides.
- **Save snapshot** → PNG (max 1600 px) uploaded to the line (max 3 per line), includes wall colour + scene metadata.

### 10.2 Technical
- Three.js via React Three Fiber + drei; lazy-loaded (separate chunk) only when preview opens.
- Frame geometry: four mitred bars from extruded profile (rectangle profile in MVP; real profile shapes later).
- Images downscaled client-side to ≤ 2048 px for textures.
- WebGL check → fallback to Level 1 (CSS 3D tilt + wall colour background + 2D frame).
- Target: interactive within 2 s on a mid-range phone after load; 60 fps desktop, ≥ 30 fps mobile.

### 10.3 Data stored
- `preview_settings` JSON on the line: scene id, wall colour, wall size, hanging height, camera position.

---

## 11. Notifications (email, MVP)

| Event | To customer | To admin side |
|---|---|---|
| Registration / verify email | ✔ | — |
| Admin-created customer invite | ✔ | — |
| Enquiry submitted | ✔ confirmation | ✔ sales/admins (or assignee) |
| Enquiry assigned | — | ✔ assignee |
| Clarification question / new message | ✔ | ✔ assignee |
| Quote needs internal approval | — | ✔ managers |
| Internal approval decision | — | ✔ sales user |
| Quote sent | ✔ with PDF + link | — |
| Quote viewed (first time) | — | ✔ sales user |
| Quote expiring in N days | ✔ | ✔ sales user |
| Quote approved / changes requested / rejected | ✔ confirmation | ✔ sales user + managers |
| Quote expired | ✔ | ✔ sales user |

- Templates editable (Email templates master), with placeholders like `{customer_name}`, `{quote_number}`, `{valid_until}`, `{link}`.
- All emails sent via queue; failures retried and logged.
- In-app notifications (bell) in admin panel for the same admin events.

---

## 12. Admin Panel (Filament)

### 12.1 Navigation
- **Dashboard**
- **Sales:** Enquiries · Quotes · Customers
- **Catalog:** Artworks · Artists · Categories · Mediums · Substrates · Standard sizes
- **Framing:** Frames · Mats · Glazing · Finishing & hardware · Quality tiers
- **Pricing:** Pricing rules · Extra charges · Taxes (GST)
- **Preview:** Wall colours · Room scenes
- **Lists:** Enquiry sources · Rejection reasons
- **System:** Users · Roles · Email templates · Settings · Activity log

### 12.2 Enquiry screen
- List: filters (status, assignee, source, project type, date range, overdue SLA), search by reference/customer/project, badges per status, "unassigned" and "awaiting me" tabs.
- Detail: header, lines with config + files + snapshots + 3D preview (read-only), message thread, internal notes, status history, quotes list, actions (assign, ask question, create quote, cancel, reopen).
- SLA indicator: time since submitted / since last customer message (threshold in settings, e.g. 24 h).

### 12.3 Quote screen
- Line editor with live pricing breakdown, cost/margin column (permission), override with reason, optional toggle, extras section, totals panel with margin and approval-needed indicator.
- Actions: save, preview PDF, submit for approval / approve / return, send, new version, withdraw, copy signed link.
- Version history + compare.

### 12.4 Customer screen
- Profile, addresses, enquiries, quotes, messages, activity, GDPR export/anonymise.

### 12.5 Dashboard widgets
- Enquiries by status (counts), new this week, unassigned, overdue SLA.
- Quotes: sent / approved / rejected / expired (period selector), conversion % (approved ÷ responded), average time submit → first quote.
- Pipeline value (open sent quotes total), approved value this month.
- Top rejection reasons; top frames / mediums quoted.

---

## 13. Customer Portal (Inertia + React)

### 13.1 Pages
- Public: landing (simple), catalog browse (optional in MVP), login, register, forgot password, verify email.
- **Dashboard:** "Action needed" (quotes awaiting response, questions to answer), recent enquiries.
- **Enquiries:** list (status, reference, project, date) → detail (lines, files, snapshots, thread, quotes).
- **New enquiry wizard:**
  1. Project details
  2. Line items (add/duplicate; each line: source → configure → preview)
  3. Review (summary + indicative total range) → Submit
  - Autosave draft every 10 s and on step change.
- **Quotes:** list → quote page (lines, optional ticks, totals, PDF download, Approve / Request changes / Reject).
- **Profile:** details, addresses, password, consents, data export.

### 13.2 UX requirements
- Mobile-first responsive; works from 360 px width.
- Unit toggle remembered per user.
- Clear diagram for "image size vs outer frame size".
- Visual frame picker (cards with corner photo, colour swatch, width).
- Inline compatibility messages, never silent changes.

---

## 14. Settings (Super Admin)

- Company: name, logo, address, **state (for GST place of supply)**, GSTIN, PAN, email, phone, website, bank details incl. IFSC (for PDF).
- Numbering: enquiry prefix/format, quote prefix/format, reset yearly.
- Quote defaults: validity days, payment terms, T&C, lead time text, reminder days before expiry.
- Internal approval: max_discount_percent, min_margin_percent, approval_threshold_amount.
- Customer estimate: show indicative estimate (on/off), range %.
- Custom creation: default revisions included.
- Uploads: max file size, allowed types.
- SLA: first-response hours.
- Default unit (cm or inch), currency fixed to INR.

---

## 15. Data Model (PostgreSQL)

> All tables have `id` (bigint), `created_at`, `updated_at`; most have `deleted_at` (soft deletes). Money = `bigint` paise. Dimensions = `integer` mm.

### 15.1 Identity & customers
- `users` — name, email, password, type (`staff`|`customer`), is_active, last_login_at
- `roles`, `permissions`, `model_has_roles`, … (spatie)
- `customers` — user_id (nullable until invite accepted), type, first_name, last_name, company_name, gstin, pan, phone, website, tax_exempt, tax_exempt_note, source_id, marketing_consent_at, terms_accepted_at, privacy_accepted_at
- `addresses` — customer_id, kind (`billing`|`delivery`), label, contact_name, phone, line1, line2, city, pin_code, state_code (GST state code), country_code (default IN), access_notes, is_default

### 15.2 Masters
- `artists`, `categories`, `artworks`, `artwork_category`, `artwork_medium`, `artwork_substrate`, `artwork_size_prices` (artwork_id, standard_size_id / w,h, price)
- `mediums`, `medium_substrate`
- `substrates`
- `frames`, `frame_substrate`
- `mats`, `glazings`, `finishings`, `finishing_substrate`
- `quality_tiers`, `standard_sizes`
- `wall_colours`, `room_scenes`
- `pricing_rules` (single active row + history), `quantity_breaks`
- `taxes`, `extra_charges`
- `enquiry_sources`, `rejection_reasons`, `email_templates`
- `settings` (spatie/laravel-settings)

### 15.3 Enquiries
- `enquiries` — reference, customer_id, project_name, project_type, source_id, delivery_address_id, required_by, budget_range, installation_needed, preferred_contact, notes, status, assigned_to, priority, internal_notes, submitted_at, first_quoted_at, cancelled_reason
- `enquiry_lines` — enquiry_id, position, source_type, artwork_id, custom_brief (jsonb: title, description, style, mood, palette, usage_rights, revisions_included), own_artwork (jsonb: service, condition_notes, rights_confirmed_at), unit_input, size_ref, width_mm, height_mm, quantity, quality_tier_id, medium_id, substrate_id, edge_style, frame_id, frame_advice_requested, mat_id, mat_border_mm, glazing_id, placement, wall_width_mm, wall_height_mm, notes, computed_dims (jsonb), estimate (jsonb), preview_settings (jsonb), warnings (jsonb)
- `enquiry_line_finishing` (pivot)
- `media` (spatie medialibrary; collections: `references`, `own_artwork_photo`, `hi_res`, `snapshots`, `attachments`)
- `messages` — enquiry_id, author_user_id, kind (`message`|`question`|`system`), body, read_at
- `status_histories` — morphs (enquiry|quote), from, to, user_id, reason, meta jsonb

### 15.4 Quotes
- `quotes` — enquiry_id, number, version, status, issue_date, valid_until, customer_snapshot jsonb, billing_snapshot jsonb, delivery_snapshot jsonb, place_of_supply_state, lead_time, payment_terms, terms (text), customer_note, internal_note, prepared_by, items_total, extras_total, discount_type, discount_value, discount_amount, taxable, tax_rate, tax_type (`intra`|`inter`), cgst_amount, sgst_amount, igst_amount, tax_amount, grand_total, total_cost, margin_percent, needs_internal_approval, internal_approved_by, internal_approved_at, internal_return_comment, sent_at, first_viewed_at, responded_at, pdf_path, superseded_by_id
- `quote_lines` — quote_id, enquiry_line_id (nullable), position, title, spec_text, image_media_id, quantity, unit_price, discount_type, discount_value, line_total, is_optional, is_selected, breakdown jsonb, is_overridden, override_reason
- `quote_extras` — quote_id, extra_charge_id (nullable), name, inclusion (`included`|`charged`|`estimated`|`excluded`), quantity, unit_price, total
- `quote_responses` — quote_id, action (`approve`|`request_changes`|`reject`), name, job_title, email, ip, user_agent, accepted_terms, po_number, po_media_id, rejection_reason_id, comment, line_comments jsonb, selected_optional_line_ids jsonb, grand_total_at_response, created_at
- `quote_views` — quote_id, ip, user_agent, viewed_at
- `document_sequences` — key (`enquiry:2026`, `quote:2026`), last_value (row-locked when incrementing)

### 15.5 System
- `activity_log` (spatie)
- `notifications` (Laravel database notifications)
- `jobs`, `failed_jobs`

### 15.6 Key indexes
- `enquiries(status, assigned_to)`, `enquiries(customer_id)`, `enquiries(reference)` unique
- `quotes(enquiry_id, version)` unique, `quotes(number, version)` unique, `quotes(status, valid_until)`
- `artworks(code)` unique, `frames(code)` unique
- GIN index on `artworks.title` for search (or `pg_trgm`)

---

## 16. Non-Functional Requirements

**Security**
- Laravel auth with email verification; staff accounts optionally with 2FA (Filament).
- Signed, expiring URLs for public quote pages; rate-limited (e.g. 30 req/min/IP); response actions require CSRF + re-check of status.
- All uploads stored on a **private** disk, served via signed temporary URLs; MIME + extension validation; image re-encoding for thumbnails; max sizes enforced.
- Authorisation policies on every model (customers see only their own data).
- Passwords hashed (bcrypt/argon2), HTTPS only, secure cookies, CSP headers.

**Data integrity**
- DB transactions for: submit enquiry, create/send quote version, record response.
- Snapshots on quotes; masters never hard-deleted when referenced.

**Performance**
- Customer pages < 2 s on 4G; admin lists paginated (25/50/100).
- Heavy work (PDF, emails, image conversions) on queues.

**Reliability & ops**
- Daily DB backups (retain 30 days) + storage backups.
- Error tracking (self-hosted / free tier) and log rotation.
- Scheduler: quote expiry, reminders, cleanup of abandoned drafts (> 90 days, notify first).

**Locale**
- English only; dates `DD MMM YYYY`; INR with Indian grouping; timezone Asia/Kolkata; units cm/inch.

**Accessibility**
- WCAG 2.1 AA for customer portal (forms, contrast, keyboard navigation). 3D preview has a non-3D alternative (summary + 2D preview).

**Browser support**
- Latest 2 versions of Chrome, Edge, Firefox, Safari; iOS Safari 16+, Android Chrome.

---

## 17. Tech Stack

| Layer | Choice |
|---|---|
| Backend | Laravel (current), PHP 8.3+ |
| Admin | Filament 4 + Filament Shield |
| Customer UI | Inertia.js + React + TypeScript + Vite, Tailwind CSS, shadcn/ui |
| Forms | React Hook Form + Zod |
| 3D | Three.js, React Three Fiber, drei |
| DB | PostgreSQL 16+ |
| Cache / queue | Redis + Laravel Horizon |
| PDF | spatie/laravel-pdf + Gotenberg (Docker) |
| Packages | spatie: permission, medialibrary, activitylog, settings; brick/money |
| Files | Local private disk (MVP) → S3-compatible later |
| Mail (dev) | Mailpit |
| Tests | Pest (unit + feature), Playwright (E2E) |
| Dev env | Laravel Herd or Docker on Windows |
| Hosting | Single VPS, Docker Compose (app, worker, scheduler, postgres, redis, gotenberg) behind Caddy |

### 17.1 Code organisation (backend)
```
app/
  Models/           (all Eloquent models — Laravel/Filament convention)
  Enums/            (UserType, Role, statuses …)
  Domain/           (business logic, no HTTP)
    Enquiries/      (EnquiryWorkflow, …)
    Quotes/         (QuoteWorkflow, QuotePdf, QuoteNumberGenerator)
    Pricing/        (PricingEngine, DimensionCalculator, CompatibilityValidator, DTOs)
  Filament/         (admin resources, pages, widgets)
  Http/Controllers/Portal/   (Inertia controllers for the customer portal)
  Settings/         (spatie/laravel-settings classes)
  Support/          (small helpers, e.g. Money)
  Notifications/
resources/js/
  pages/ components/ preview3d/ lib/
```

---

## 18. Milestones

| # | Milestone | Deliverables |
|---|---|---|
| M0 | Setup | Repo, Docker/Herd, CI (lint + tests), Filament admin login, Inertia portal skeleton, roles |
| M1 | Masters | All masters CRUD, media, compatibility fields, settings, seed data |
| M2 | Customers & enquiry | Registration, profile/addresses, enquiry wizard with lines, uploads, draft autosave, submit, admin enquiry screens, message thread |
| M3 | Pricing engine | Dimension calculator, compatibility validator, pricing engine + unit tests, live estimate in form |
| M4 | Quotes | Quote builder, versions, extras, internal approval, PDF, send email + signed link |
| M5 | Customer response | Approve / request changes / reject, optional lines, expiry + reminders, all notifications |
| M6 | 3D preview | Room scenes, wall colours, framed 3D object, multi-piece wall, snapshots, fallback |
| M7 | Dashboard & hardening | Widgets, audit views, GDPR export, security review, E2E tests, deployment |

---

## 19. Acceptance Criteria (MVP done when)

1. Admin can create every master; inactive items disappear from the customer form but remain on old records.
2. A customer can register, verify email, create an enquiry with ≥ 3 lines of different source types, upload files, preview in 3D, save snapshots and submit.
3. Incompatible combinations are blocked or warned exactly per §4.3.
4. Sales can create a quote from the enquiry with prices from the engine; changing a master price afterwards does not change that quote.
5. A quote with a discount above the limit cannot be sent until a Manager approves it.
6. Customer receives an email with PDF and signed link; can approve without logging in; approval record contains name, title, IP, timestamp, selected optional lines and total.
7. Request changes → sales creates v2 → v1 shows as superseded; customer sees both.
8. Rejection requires a reason and appears in the dashboard's rejection reasons widget.
9. Unanswered quote expires automatically on the day after valid_until, with a reminder sent N days before.
10. Every status change and master change is visible in the audit log.
11. Customer portal works on a 360 px wide phone; 3D preview falls back gracefully without WebGL.
12. Pricing engine, workflows and compatibility validator have automated tests; E2E test covers enquiry → quote → approval.

---

## 20. Open Questions (to confirm before M1)

1. ~~Base currency & tax~~ → **INR + GST** (decided v1.1). Confirm company's state and default GST rate (18%?).
2. ~~Languages~~ → **English only** (decided v1.1).
3. **Show indicative price to customers** in the form — yes/no by default?
4. **Guest enquiries:** must customers register before submitting, or allow guest submit with email verification?
5. **Frame pricing model:** per metre of moulding (as specced) or per united inch/cm?
6. **Catalog artworks:** fixed price per size, per m², or both depending on artwork?
7. **Custom creation pricing:** is the manual complexity amount enough, or do you want complexity levels (simple / medium / complex) as a master?
8. **Partial approval:** is ticking optional lines enough, or must customers approve/reject individual mandatory lines?
9. **Approved quote changes:** once approved, can it be revised (new version) before the next phase exists, or is it locked?
10. **Branding:** company name, logo, colours and legal details for the PDF and portal.
11. **Public catalog:** should the catalog be browsable without login (marketing value), or only inside the portal?
