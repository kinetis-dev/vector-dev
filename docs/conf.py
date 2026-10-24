# Configuration file for the Sphinx documentation builder.
# https://www.sphinx-doc.org/en/master/usage/configuration.html
#
# The same build as the Kinetis documentation (kinetis/docs): MyST
# Markdown and the shared kinetis-website-theme.

import os

project = "Kinetis Vector"
copyright = "2026, Alon Noy"
author = "Alon Noy"
# No release or version: kinetis/vector and @kinetis/vector-ui version
# independently (packages.manifest.json), so no one number describes the docs.

extensions = [
    "myst_parser",
    "sphinx_copybutton",
    "sphinx_design",
    "sphinxext.opengraph",
    "sphinx_sitemap",
    "sphinx.ext.intersphinx",
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

exclude_patterns = ["_build", "baseline", "tools", "Thumbs.db", ".DS_Store"]


def setup(app):
    # Pygments' stock PHP lexer only starts highlighting inside a literal
    # `<?php` tag; the snippets here are partial fragments without one.
    # startinline=True lexes the whole block as PHP regardless.
    from pygments.lexers.php import PhpLexer
    from sphinx.highlighting import lexers

    lexers["php"] = PhpLexer(startinline=True)

# -- HTML output -------------------------------------------------------------

html_theme = "kinetis_website_theme"
html_static_path = ["_static"]
# Copied verbatim to the build root, so served under /vector/docs/:
# llms.txt, the plain-text index AI coding tools look for
# (https://llmstxt.org), at /vector/docs/llms.txt.
html_extra_path = ["_extra"]
# No html_logo or html_favicon: the theme supplies the navbar logo and
# the links to kinetis-website's own /assets/favicon.* files.
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
# Written to sitemap.xml at the build root.
sitemap_url_scheme = "{link}"

# -- Intersphinx --------------------------------------------------------------
# Links into the Kinetis documentation resolve against its live inventory,
# so a page or label Kinetis removes fails this build under -W. With every
# reftype disabled, only explicit inv:kinetis:... links resolve there; a
# plain {doc} or {ref} stays local.
intersphinx_mapping = {"kinetis": ("https://kinetis.dev/docs/", None)}
intersphinx_disabled_reftypes = ["*"]

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
