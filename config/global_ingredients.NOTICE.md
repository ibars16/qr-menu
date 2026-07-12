# Data source & attribution — Global Ingredient Library

`global_ingredients.csv` is generated (via `app:global-ingredients:generate-dataset`,
see `src/Command/GenerateGlobalIngredientsDatasetCommand.php`) from the
**Open Food Facts ingredients taxonomy**:

- Source: https://github.com/openfoodfacts/openfoodfacts-server (`taxonomies/food/ingredients.txt`)
- License: Open Database License (ODbL) 1.0 — https://opendatacommons.org/licenses/odbl/1-0/
- © Open Food Facts contributors — https://world.openfoodfacts.org

The ODbL permits commercial use and redistribution, and permits derivative
databases (such as this normalized, de-duplicated CSV), provided attribution
is retained and any distributed derivative database remains open under
ODbL-compatible terms. This file is that attribution notice; keep it
alongside `global_ingredients.csv` if the file is moved or repackaged.
