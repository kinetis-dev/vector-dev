(ai-filters)=
# Plain-language filters

An admin types the records they want (*orders over $500 from last week
that haven't shipped*) into the field with a sparkle icon beside a
list's search, and the list filters at once. The answer goes through the
ordinary list request, which validates it again, and shows as the
filter bar's own chips. A note below the field says in one sentence what
the filter keeps; its **Undo** restores the list's filter and search.
The sentence stays in the field, to refine and send again.

```{image} ../_static/screenshots/ai-filters-light.png
:alt: The Orders list filtered by “paid orders over 500”, with the explaining note and its Undo, and the chips Status is Paid and Total over 500.00
:class: only-light
```

```{image} ../_static/screenshots/ai-filters-dark.png
:alt: The Orders list filtered by “paid orders over 500”, with the explaining note and its Undo, and the chips Status is Paid and Total over 500.00
:class: only-dark
```

A relation named in words is looked up like the filter bar's choice
list, under the user's scope: one match becomes its id, and several wait
in the note for the admin to pick one. A resource kept from the model
offers no filter ({ref}`ai-boundary`). The request and its repair turn
are in {ref}`ai-filter-request`.
