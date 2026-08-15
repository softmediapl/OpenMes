# Product Revision and Process Template Ownership

## Decision

Product revisions do not own a single process template directly. Instead, each
process template declares the product revision it applies to.

In data terms:

- `product_revisions` represent product generations and lifecycle.
- `process_templates.product_revision_id` identifies the revision for a
  concrete process+BOM variant.
- A single released product revision may have many active process templates.

## Why

Issue #180 introduced product revisions as the released configuration that binds
product, process, BOM, documents and quality requirements. The first
implementation simplified that to `product_revisions.process_template_id`, which
made one revision point to one process template.

That is too narrow for production domains where a product revision can have
several released process+BOM variants. Ornament manufacturing is the practical
example: "80 mm glass bauble, 2026 revision" is the product generation, while
"red lacquer with gold stars" and "blue lacquer with glitter" can be variants of
the production route or BOM. A colorway may change one paint material or one
decoration step; it should not automatically become a new engineering revision.

Issue #104 added multi-BOM selection on work orders. This decision aligns that
feature with #180: a work order still chooses one product revision, and then
chooses one or more process+BOM variants that are valid for that revision.

Issue #182 requires controlled changes after production starts. This remains the
boundary: changing the product revision or the selected process+BOM variants
after batches exist must go through change control and create a new snapshot
version instead of rewriting history.

## Migration Policy

The migration intentionally does not infer new template-to-revision links from
the old `product_revisions.process_template_id` column.

Existing process templates are preserved, but their `product_revision_id` starts
empty until an admin deliberately assigns the correct revision. This avoids
silently creating misleading configuration history in existing installations.

## Example

Product type:

- `B-K80` - 80 mm glass bauble

Product revision:

- `2026-014` - 2026 released product generation

Process templates for that revision:

- `Silvered red bauble with gold stars`
- `Silvered blue bauble with glitter`
- `Transparent bauble without silvering`

A later revision such as `2027-001` should be created for a larger product
change, for example a new product construction, substantially changed route,
new quality basis, or new released engineering package. Its process+BOM variants
then point to `2027-001`.
