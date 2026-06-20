# Domain Monitor dashboard widget mockup: WordPress-native orb

## Design stance

The widget should look like it belongs in `wp-admin`. The only custom visual idea is the status orb. Everything else should use familiar WordPress dashboard patterns: postbox chrome, core admin colors, ordinary links, standard button treatment, restrained spacing, and notice-like detail areas.

## Key choices

- **Native shell:** standard WordPress dashboard postbox shape, border, header, and `.inside` spacing.
- **Primary signal:** one large status orb centered in the widget.
- **Core colors:** WordPress admin green/yellow/red/blue rather than a custom palette.
- **Default copy:** minimal: “All's well,” the domain, and a quiet checked timestamp.
- **Detail behavior:** healthy state hides details; yellow/red states reveal one short notice-style explanation.
- **Tone:** calm, not branded or marketing-like.

## Proposed state model

- **Healthy / green:** all important checks passed. The user should be able to ignore the widget.
- **Watch / yellow:** something uncertain or incomplete, but not urgent. Example: expiration date unknown while DNS still resolves.
- **Attention / red:** action likely needed. Example: latest DNS check failed.

## Product note

This is deliberately less informational than the current dashboard widget. For the 99% single-domain case, the dashboard should answer one question: “Do I need to care right now?” If the orb is green, no.

## File

Open the HTML mockup at:

```text
docs/ux/dashboard-widget-orb-mockup.html
```
