# BMN Real Estate Child Theme (flavor-flavor-flavor)

A custom real estate homepage theme for Steven Novak / BMN Boston Real Estate, built as a child theme of GeneratePress.

## Version: 1.2.7

**Last Updated:** 2025-11-30

## Requirements

- WordPress 6.0+
- GeneratePress theme (parent theme)
- MLS Listings Display plugin (for property search and analytics features)
- Bridge MLS Extractor Pro plugin (for MLS data)

## Features

### Homepage Sections
- **Hero Section** - Agent photo, contact info, social links, and quick property search
- **Services** - Service offerings display
- **About Us** - Team stats and description
- **Neighborhood Analytics** - Market data cards for Boston neighborhoods
- **Market Analytics** - City-based market insights using MLD shortcodes
- **Featured Neighborhoods** - Neighborhood showcase grid
- **Newest Listings** - Latest property listings
- **Featured Cities** - City showcase grid
- **Team** - Agent team display
- **Testimonials** - Client testimonials carousel
- **Blog** - Latest blog posts

### Mobile-First Design
- Fully responsive layout with breakpoints at 480px, 640px, 768px, and 1024px
- Touch-friendly navigation with slide-in drawer menu
- Dynamic hero image sizing using viewport units
- Glass-morphism effects with fallbacks
- Safe area support for notched devices

### Customizer Options
Located in **Appearance → Customize**:

- **Homepage Hero** - Agent name, title, license, photo, phone, email, address, team name
- **Homepage Layout** - Drag-and-drop section reordering
- **Neighborhood Analytics** - Featured neighborhoods configuration
- **Market Analytics** - City-based market insights settings
- **Social Media Links** - Instagram, Facebook, YouTube, LinkedIn
- **About Section** - Stats and description
- **Featured Locations** - Neighborhoods and cities lists
- **Brokerage Branding** - Logo and name

## Installation

1. Install and activate the GeneratePress theme
2. Upload this theme via **Appearance → Themes → Add New → Upload Theme**
3. Activate the child theme
4. Configure settings in **Appearance → Customize**

## Changelog

### Version 1.2.7 (2025-11-30)
**Mobile Responsive Overhaul**

#### Hero Section
- Hero image now positioned at top with 10px margin (was 70-100px)
- Dynamic hero photo sizing: 40-50vh on mobile, scales with viewport
- Reduced vertical spacing throughout hero section (25-35% reduction)
- Added missing customizer fields: Agent Email, Office Address, Team/Group Name, Team/Group URL

#### Mobile Drawer Navigation
- Fixed oversized logo issue - now properly constrained to 40px height
- Hidden legacy mobile menu that was appearing behind drawer
- Improved logo constraints for WordPress custom logos

#### Section Visibility
- Added CSS fallback to ensure all sections visible on mobile
- Fixed IntersectionObserver animation issues on mobile devices
- Sections now use `opacity: 1 !important` on mobile as fallback

#### General Mobile Fixes
- Reduced section padding from 64px to 32px on mobile
- Tighter search form layout with reduced margins
- Single-column grids enforced on screens below 480px
- Container padding reduced to 12px on extra small phones

### Version 1.2.6 (2025-11-30)
- Initial mobile responsive fixes
- Dynamic vh-based hero photo sizing
- Mobile section spacing adjustments

### Version 1.2.5 (2025-11-29)
- Full-width dark glass search form with location autocomplete
- Hero section refinements

### Version 1.2.4 (2025-11-28)
- Footer updates and MLS helper improvements

### Version 1.2.3 (2025-11-27)
- Section manager and customizer controls

### Version 1.2.1 (2025-11-26)
- Market analytics section with MLD shortcodes

### Version 1.1.1 (2025-11-25)
- Mobile drawer navigation with glass-morphism
- Header scroll state improvements

### Version 1.0.15 (2025-11-24)
- Initial release with homepage sections

## File Structure

```
flavor-flavor-flavor/
├── assets/
│   ├── css/
│   │   ├── components.css      # Header, footer, shared components
│   │   ├── homepage.css        # Homepage section styles
│   │   ├── mobile-drawer.css   # Mobile navigation drawer
│   │   └── ...
│   └── js/
│       ├── homepage.js         # Carousels, animations, search
│       ├── mobile-drawer.js    # Drawer toggle functionality
│       └── ...
├── inc/
│   ├── class-theme-setup.php   # Theme setup and customizer
│   ├── class-mls-helpers.php   # MLS data helper functions
│   ├── class-section-manager.php
│   └── ...
├── template-parts/
│   └── homepage/
│       ├── section-hero.php
│       ├── section-analytics.php
│       ├── section-listings.php
│       └── ...
├── functions.php
├── header.php
├── footer.php
├── front-page.php
├── style.css
└── README.md
```

## CSS Variables

The theme uses CSS custom properties for consistent styling:

```css
--bne-primary-blue: #4a60a1
--bne-beige: #decdd2
--bne-dark-gray: #282c1c
--bne-space-1 through --bne-space-16 (spacing scale)
--bne-font-size-sm through --bne-font-size-4xl (fluid typography)
```

## Support

For issues or feature requests, contact the development team.

## License

GNU General Public License v2 or later
