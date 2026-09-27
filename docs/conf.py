# Configuration file for the Sphinx documentation builder.
# https://www.sphinx-doc.org/en/master/usage/configuration.html
#
# The same build as the Kinetis documentation (kinetis/docs): MyST
# Markdown, the Furo theme, and the Kinetis palette.

import os

project = "Kinetis Vector"
copyright = "2026, Alon Noy"
author = "Alon Noy"
release = "0.1.0-dev"

extensions = [
    "myst_parser",
    "sphinx_copybutton",
    "sphinx_design",
    "sphinxext.opengraph",
    "sphinx_sitemap",
]

myst_enable_extensions = [
    "colon_fence",
    "deflist",
    "fieldlist",
    "substitution",
    "tasklist",
]

# Auto-generates an #anchor-slug for every heading up to this depth, so
# in-page links like [text](#some-heading) resolve without each heading
# needing an explicit {#custom-id}.
myst_heading_anchors = 4

source_suffix = {
    ".md": "markdown",
}

templates_path = ["_templates"]
exclude_patterns = ["_build", "Thumbs.db", ".DS_Store"]


def setup(app):
    # Pygments' stock PHP lexer only starts highlighting inside a literal
    # `<?php` tag; the snippets here are partial fragments without one.
    # startinline=True lexes the whole block as PHP regardless.
    from pygments.lexers.php import PhpLexer
    from sphinx.highlighting import lexers

    lexers["php"] = PhpLexer(startinline=True)

# -- HTML output -------------------------------------------------------------

html_theme = "furo"
html_static_path = ["_static"]
# Copied verbatim to the site root: 404.html, robots.txt and llms.txt,
# the plain-text index AI coding tools look for (https://llmstxt.org).
html_extra_path = ["_extra"]
html_css_files = ["custom.css"]
# Vector is part of Kinetis and carries its logo, as the repository does.
html_logo = "_static/logo.svg"
# Unset on purpose, as in the Kinetis build: the favicon is
# kinetis-website's own /assets/favicon.svg, emitted by
# _templates/page.html's `extrahead` block.
html_title = "Kinetis Vector"

# The deployed URL, used for sitemap.xml, canonical links and Open Graph
# URLs. VECTOR_DOCS_BASEURL overrides it for another host or prefix.
html_baseurl = os.environ.get("VECTOR_DOCS_BASEURL", "https://kinetis.dev/vector/docs/")

# -- Open Graph / social previews --------------------------------------------
# sphinxext-opengraph builds each page's description and og:*/twitter:*
# tags from its first paragraph; the image is the Kinetis logo render.
ogp_site_url = html_baseurl
ogp_site_name = "Kinetis Vector"
ogp_image = "_static/og-image.png"

# -- Sitemap ------------------------------------------------------------------
# Written to sitemap.xml at the build root; robots.txt points at it.
sitemap_url_scheme = "{link}"

html_theme_options = {
    # The logo's secondary-title amber, in both modes, as in Kinetis.
    "light_css_variables": {
        "color-brand-primary": "#D97706",
        "color-brand-content": "#D97706",
        "color-brand-visited": "#D97706",
    },
    "dark_css_variables": {
        "color-brand-primary": "#D97706",
        "color-brand-content": "#D97706",
        "color-brand-visited": "#D97706",
    },
}

# -- LaTeX / PDF output ------------------------------------------------------
# `make latexpdf`. The HTML build is the one built and checked; PDF output
# is configured as in Kinetis but not verified.

latex_engine = "xelatex"
latex_elements = {
    "papersize": "letterpaper",
    "pointsize": "10pt",
}
latex_documents = [
    ("index", "kinetis-vector.tex", "Kinetis Vector Documentation", author, "manual"),
]
