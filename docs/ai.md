# AI

`kinetis/vector-ai` adds AI to the admin:

- **The assistant** answers questions about your data ({ref}`ai-assistant`)
  and, where allowed, proposes changes a person approves
  ({ref}`ai-approvals`).
- **Plain-language filters** turn a described set of records into a list
  filter ({ref}`ai-filters`).
- **Writing help** rewrites, shortens, corrects or translates a text
  field ({ref}`ai-writing`).
- **Bulk rewrite proposals** produce a CSV of rewrites to review and
  import ({ref}`ai-bulk`).

It is a companion Composer package, `kinetis/vector-ai`, whose UI is
part of `@kinetis/vector-ui` and turns itself on when the API publishes
`capabilities.ai`. It is built on
[`kinetis/neuron`](https://kinetis.dev/docs/neuron.html), so a provider
call suspends the calling Fiber instead of blocking the worker. Setup
follows the features ({ref}`ai-installation`), then what they all share.

## See also

- {doc}`deployment`: the hosted and standalone modes.
- {doc}`customizing-the-ui`: the UI extension points the AI features are built on.
- {doc}`settings`: settings groups.
