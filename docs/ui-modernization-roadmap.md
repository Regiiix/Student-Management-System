# UI Modernization Roadmap

## Goal
Modernize the whole system UI with consistent design language, improved readability, and better usability across desktop and mobile while preserving current PHP workflows.

## Success Criteria
- Shared visual system applied across all pages
- Consistent spacing, typography, buttons, cards, and table behavior
- Better mobile layout and accessibility
- No regressions in existing forms, filters, and data actions

## Phase 1: Foundation (Design Tokens + Base Components)

### Files to Update
- css/common.css
- css/forms_bundle.css
- css/details.css

### Tasks
1. Define a unified token set in css/common.css
- Color tokens: background, surface, text, border, brand, success, warning, danger
- Spacing scale: 4, 8, 12, 16, 20, 24, 32
- Radius scale: small, medium, large
- Shadow scale: subtle, medium, elevated
- Transition tokens: fast and normal

2. Standardize typography
- One body font and one heading font style
- Clear heading scale for h1, h2, h3
- Consistent line-height for forms and tables

3. Build reusable component styles
- Button variants: primary, secondary, ghost, danger
- Unified input/select/textarea appearance
- Unified card style (padding, border, radius, shadow)

4. Add global focus and accessibility styles
- Visible focus ring for keyboard users
- Improved contrast for text and UI controls

### Acceptance Checklist
- Buttons look and behave consistently across pages
- Inputs have consistent focus and error states
- Cards have the same visual rhythm
- No clipped text at common widths

## Phase 2: App Shell and Navigation

### Files to Update
- config/sidebar.php
- css/common.css
- js/app.js

### Tasks
1. Modernize sidebar hierarchy
- Strong active indicator
- Better spacing and icon alignment
- Section grouping clarity (Students, Academics, Enrollment, Finance)

2. Add stable page shell behavior
- Sticky page header region where useful
- Better top spacing consistency between pages

3. Improve mobile nav interactions
- Smooth open/close transitions
- Clear overlay and close behavior
- Keep keyboard escape close support

### Acceptance Checklist
- Sidebar active state is obvious
- Mobile nav is easy to open/close
- Navigation labels remain readable on small screens

## Phase 3: Data Table Experience

### Files to Update
- index.php
- pages/finance.php
- pages/scholarships.php
- pages/enrollment.php
- css/index.css
- css/reports_bundle.css

### Tasks
1. Standardize table layout and behavior
- Sticky headers
- Cleaner row hover and zebra striping
- Consistent table density

2. Improve filter bars and chips
- Uniform search/filter row spacing
- Standard reset and clear actions
- Better chip readability

3. Normalize row actions
- One action pattern for all tables
- Keep destructive actions visually distinct

4. Add polished empty/loading states
- Icon + short helper message for no results
- Consistent skeleton/loading treatment

### Acceptance Checklist
- All table pages share one visual style
- Filters look and work consistently
- Empty states are informative and polished

## Phase 4: Forms and Workflows

### Files to Update
- pages/add_student.php
- pages/edit_student.php
- pages/edit_grades.php
- pages/student_finance.php
- css/forms_bundle.css

### Tasks
1. Create sectioned form layout
- Group related fields under clear section titles
- Reduce long unbroken form stacks

2. Improve validation feedback
- Inline helper/error text
- Consistent success and warning messages

3. Improve action footer
- Sticky save/cancel row on long forms
- Consistent button order and labels

### Acceptance Checklist
- Long forms feel easier to scan
- Validation feedback is clear and consistent
- Action buttons remain easy to find

## Phase 5: Dashboards and Analytics Visuals

### Files to Update
- pages/dashboard.php
- index.php (quick stats modal)
- css/index.css

### Tasks
1. Unify stat card style
- Consistent icon sizing, title weight, and metric emphasis

2. Improve chart legibility
- Palette aligned with brand tokens
- Better axis text contrast and spacing

3. Improve dashboard state handling
- Cleaner loading and error visuals
- Better empty data handling

### Acceptance Checklist
- Charts and stats are visually coherent
- Quick stats modal feels integrated with app theme

## Phase 6: Landing and Brand Consistency

### Files to Update
- landing.php
- css/index.css

### Tasks
1. Keep school-style landing as the external face
- Institutional copy and hierarchy
- Service-oriented calls to action

2. Align internal page visuals with landing identity
- Heading treatment and accent colors
- Shared card and button language

### Acceptance Checklist
- Landing and internal pages feel like one product
- Visual transition from landing to app is seamless

## Cross-Cutting Accessibility and QA

### Mandatory Checks per Phase
1. Keyboard testing
- Tab order, focus visibility, modal escape close

2. Responsive checks
- 1366x768, 1280x720, 1024x768, 768x1024, 390x844

3. Contrast checks
- Text, badges, buttons, table controls

4. Regression checks
- Student add/edit/drop
- Enrollment flow
- Finance viewing and payments
- Scholarships management
- Promotion modal flows

## Suggested Execution Order (2-Week Practical Plan)
1. Days 1-2: Phase 1 foundation
2. Days 3-4: Phase 2 shell/nav
3. Days 5-7: Phase 3 tables and filters
4. Days 8-9: Phase 4 forms
5. Day 10: Phase 5 dashboard visuals
6. Day 11: Phase 6 landing/internal consistency
7. Days 12-14: QA polish and accessibility fixes

## Quick Wins (Can Be Done Immediately)
1. Standardize button variants in css/common.css
2. Unify input focus and border styles in css/forms_bundle.css
3. Add sticky headers for major tables in css/index.css
4. Normalize empty-state presentation on index, finance, scholarships
5. Harmonize filter chip spacing and reset controls
