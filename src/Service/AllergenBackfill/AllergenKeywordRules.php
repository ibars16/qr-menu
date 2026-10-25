<?php

namespace App\Service\AllergenBackfill;

/**
 * Deterministic, AI-free check on an ingredient name — the third opinion
 * next to the two Groq passes in IngredientAllergenBackfill. It never
 * decides anything on its own: it only says which allergens a name
 * obviously implies ("expected": "Egg yolk" → eggs, "queso emmental" →
 * milk) and which ones a name obviously rules out ("forbidden": "Coconut
 * milk" is not milk, "Oyster mushroom" is not a mollusc). A proposal that
 * misses an expected allergen or carries a forbidden one goes to human
 * review instead of being applied. A third list, "review", sends a name
 * to human review no matter what: composite products and ambiguous words.
 * A fourth, "processed", only matters when the AI proposes no allergens at
 * all: "Yeast extract" or "Dried fig" with nothing on it goes to review.
 *
 * Names are matched accent- and case-insensitively in the languages the
 * library and the restaurants actually use (en, es, ca, plus the common
 * it/fr loanwords on menus). Deliberately conservative: a keyword only
 * goes here when the name alone settles it — "manteca" (lard in Spain,
 * butter in Argentina), "shellfish" (crustacean or mollusc?) or "pesto"
 * (pine nuts aren't one of the 14) have no rule at all.
 *
 * The first keyword matched is also the ingredient's "root", which
 * IngredientAllergenBackfill sorts batches by, so the model sees "Egg",
 * "Egg white" and "Free range eggs" side by side and labels them alike.
 */
final class AllergenKeywordRules
{
    /** allergen code => keyword regex fragments (accent-free, lowercase). */
    private const EXPECT = [
        'eggs' => ['eggs?', 'yolks?', 'albumen', 'mayonnaise', 'mayo', 'meringues?', 'huevos?', 'yemas?', 'mayonesa', 'merengues?', 'uovo', 'uova', 'tuorlo', 'oeufs?'],
        'milk' => [
            'milks?', 'cheeses?', 'butter', 'buttermilk', 'cream', 'yog(?:h)?urts?', 'whey', 'casein(?:ate)?', 'lactose', 'ghee', 'kefir',
            'mozzarella', 'parmesan', 'parmigiano', 'cheddar', 'ricotta', 'mascarpone', 'burrata', 'emmental', 'gouda', 'brie', 'camembert',
            'feta', 'provolone', 'gorgonzola', 'pecorino', 'bechamel', 'parmesano',
            'leche', 'queso', 'quesos', 'mantequilla', 'nata', 'yogures?', 'lactosa', 'requeson',
            'latte', 'formaggio', 'burro', 'panna', 'lait', 'fromage', 'beurre', 'llet', 'formatge', 'mantega',
        ],
        'gluten' => [
            'wheat', 'barley', 'rye', 'spelt', 'kamut', 'oats?', 'oatmeal', 'semolina', 'couscous', 'bulgur', 'seitan', 'malt(?:ed)?',
            'flour', 'breads?', 'breadcrumbs?', 'pasta', 'spaghetti', 'beer', 'farro', 'triticale',
            'trigo', 'cebada', 'centeno', 'espelta', 'avena', 'semola', 'cuscus', 'harina', 'pan', 'rebozad[oa]s?', 'empanad[oa]s?',
            'galletas?', 'bizcochos?', 'espaguetis', 'macarrones', 'ravioles', 'raviolis?', 'cerveza', 'blat', 'farina',
        ],
        'fish' => [
            'fish', 'anchov(?:y|ies)', 'cod', 'salmon', 'tuna', 'sardines?', 'mackerel', 'trout', 'hake', 'haddock', 'herring', 'halibut',
            'monkfish', 'swordfish', 'tilapia', 'pollock', 'bonito', 'turbot', 'surimi', 'sea bass',
            'pescados?', 'anchoas?', 'boquerones?', 'bacalao', 'atun', 'ventresca', 'sardinas?', 'caballa', 'truchas?', 'merluza', 'lubina',
            'dorada', 'pez espada', 'rodaballo', 'lenguado', 'peix', 'tonyina',
        ],
        'crustaceans' => [
            'shrimps?', 'prawns?', 'crabs?', 'lobsters?', 'crayfish', 'langoustines?', 'scampi', 'krill',
            'gambas?', 'gambon(?:es)?', 'langostinos?', 'cangrejos?', 'langostas?', 'bogavantes?', 'cigalas?', 'carabineros?', 'quisquillas?',
            'centollos?', 'necoras?', 'gamba', 'llagosta',
        ],
        'molluscs' => [
            'mussels?', 'clams?', 'oysters?', 'scallops?', 'squids?', 'octopus', 'cuttlefish', 'snails?', 'escargots?', 'whelks?', 'abalone',
            'cockles?', 'calamari',
            'mejillon(?:es)?', 'almejas?', 'ostras?', 'vieiras?', 'calamar(?:es)?', 'pulpo', 'sepias?', 'chipiron(?:es)?', 'caracol(?:es)?',
            'berberechos?', 'navajas?', 'musclos?', 'pop',
        ],
        'peanuts' => ['peanuts?', 'groundnuts?', 'cacahuetes?', 'cacahuates?', 'mani', 'cacauets?'],
        'nuts' => [
            'almonds?', 'hazelnuts?', 'walnuts?', 'cashews?', 'pecans?', 'brazil nuts?', 'pistachios?', 'macadamias?', 'praline',
            'marzipan', 'almendras?', 'avellanas?', 'nuez', 'nueces', 'anacardos?', 'pacanas?', 'pistachos?', 'frutos secos',
            'mazapan', 'almendrad[oa]s?', 'ametllats?', 'mixed nuts', 'tree nuts?', 'ametllas?', 'nous', 'mandorle?', 'nocciole?', 'noci',
        ],
        'soybeans' => ['soy', 'soya', 'soybeans?', 'tofu', 'tempeh', 'edamame', 'miso', 'shoyu', 'tamari', 'soja'],
        'celery' => ['celery', 'celeriac', 'apio', 'apionabo', 'api'],
        'mustard' => ['mustard', 'mostaza', 'moutarde', 'mostassa', 'senape'],
        'sesame' => ['sesame', 'tahini', 'tahina', 'gomasio', 'sesamo', 'ajonjoli'],
        'sulphites' => ['sulph?ites?', 'sulfites?', 'sulfur dioxide', 'metabisulph?ite', 'e ?22[0-8]', 'wines?', 'vino', 'vins?', 'cava', 'vermouth', 'vermut', 'sulfitos?'],
        'lupin' => ['lupins?', 'lupines?', 'lupini', 'altramuz', 'altramuces', 'tramusos'],
    ];

    /**
     * allergen code => phrases that rule it out. They also cancel the
     * matching "expected" entry: "Coconut milk" expects nothing.
     */
    private const FORBID = [
        'milk' => [
            '(?:coconut|oat|soy|soya|almond|rice|cashew|hazelnut|hemp|pea|plant) (?:milk|drink|cream|yog(?:h)?urt)', 'milk caps?', 'milk thistle',
            '(?:peanut|cocoa|cacao|shea|nut|nutmeg|coconut|almond|cashew|hazelnut|apple|mango|kokum|illipe|sal|ucuhuba|murumuru|cupuacu|tucuma|mowrah) butter', 'cream of tartar', 'cream soda',
            '(?:leche|bebida|nata) (?:de|vegetal de) (?:coco|avena|soja|almendras?|arroz|anacardos?)', 'manteca de (?:cacao|cacahuete)',
            'mantequilla de (?:cacahuete|cacao|almendras?)', 'sin lactosa', 'butter beans?', 'cream crackers?',
        ],
        'gluten' => [
            '(?:rice|corn|maize|chickpea|almond|coconut|potato|tapioca|cassava|buckwheat|soy|gram|quinoa|chestnut|teff|lentil|cottonseed|pea|bean|sorghum|millet|amaranth|hemp|flax|linseed|sunflower|pumpkin|banana|carob|arrowroot|acorn|lupin|peanut|sesame|hazelnut) flour',
            'cornflour', 'buckwheat', 'teff',
            'gluten[- ]free', 'sin gluten', 'harina de (?:arroz|maiz|garbanzos?|almendras?|coco|patata|yuca|mandioca|trigo sarraceno|castanas?|quinoa|lentejas?|teff|sorgo|mijo|algarroba|altramuz)',
            'rice (?:pasta|noodles|bread)', '(?:maize|corn|rice) semolina', 'semola de (?:maiz|arroz)', 'pan de (?:yuca|arroz)', '(?:ginger|root) beer',
        ],
        // "Oyster" is also a cut of poultry: the two round pieces of dark meat on the back.
        'molluscs' => ['oyster mushrooms?', 'setas? de ostra', 'girgolas?', '(?:chicken|turkey|duck|goose|emu|ostrich|poultry|fowl) oysters?'],
        'nuts' => ['nutmeg', 'nuez moscada', 'nou moscada', 'coconuts?', 'pine nuts?', 'pinones?', 'chestnuts?', 'castanas?', 'water chestnuts?'],
        'eggs' => ['egg ?plants?', 'egg[- ]free', 'sin huevo'],
        'fish' => ['fish[- ]free'],
        'celery' => ['apio de monte'],
    ];

    /**
     * Reason => keywords for names that always go to human review, whatever
     * the AI says: composite products whose allergens depend on a recipe
     * nobody can see from the name (sauces, biscuits, bread, cured meats,
     * chocolate, ice cream…), and words that mean different things in
     * different places. The pilot run auto-applied exactly this kind of
     * item wrong while both passes agreed (Worcestershire without gluten,
     * "caramelo" as milk, "manteca" as nothing).
     */
    private const REVIEW = [
        'compuesto o de receta variable: los alérgenos dependen de la receta' => [
            // sauces & dressings
            'sauces?', 'salsas?', 'dressings?', 'vinaigrettes?', 'vinagretas?', 'mayonnaise', 'mayo', 'mayonesa', 'ketchup', 'gravy', 'pesto',
            'aioli', 'alioli', 'all i oli', 'chutneys?', 'relish', 'dips?', 'hummus', 'spreads?', 'untables?', 'bechamel', 'carbonara',
            'chimichurri', 'adobo', 'marinades?', 'escabeche', 'curry pastes?', 'stock', 'broth', 'bouillon', 'caldo', 'fondo', 'stock cubes?',
            'pastillas? de caldo', 'seasonings?', 'sazonador', 'spice mix', 'curry powder', 'condiments?',
            // bakery, sweets
            'biscuits?', 'cookies?', 'crackers?', 'galletas?', 'cakes?', 'pastr(?:y|ies)', 'pies?', 'tarts?', 'tartas?', 'pasteles?',
            'bizcochos?', 'croissants?', 'brioches?', 'muffins?', 'magdalenas?', 'waffles?', 'gofres?', 'pancakes?', 'crepes?', 'doughnuts?',
            'donuts?', 'bolleria', 'bread', 'breads', 'buns?', 'rolls?', 'baguettes?', 'focaccia', 'pan', 'panes', 'panecillos?', 'pan rallado',
            'breadcrumbs?', 'dough', 'masa', 'pizza', 'pizzas', 'base de pizza', 'caramel', 'caramels', 'caramelos?', 'toffee', 'fudge',
            'nougat', 'turron', 'praline', 'candy', 'sweets', 'golosinas?', 'chocolates?',
            'custard', 'natillas', 'flan', 'puddings?', 'mousse', 'desserts?', 'postres?', 'ice creams?', 'helados?', 'gelato', 'sorbets?',
            'sorbetes?', 'granola', 'muesli', 'cereal bars?', 'barritas?', 'dulce de leche', 'syrups?', 'siropes?', 'jarabes?', 'topping',
            // raising agents
            'baking powder', 'levadura quimica', 'polvo de hornear', 'polvos de hornear', 'gasificantes?', 'impulsor',
            // stuffed/fresh pasta & dumplings
            'ravioli', 'raviolis', 'ravioles', 'tortellini', 'tortelloni', 'cappelletti', 'agnolotti', 'gnocchi', 'noquis', 'lasagnes?',
            'lasanas?', 'canelones', 'cannelloni', 'empanadillas?', 'empanadas?', 'dumplings?', 'gyozas?', 'wontons?',
            // cured & processed meat
            'sausages?', 'salami', 'chorizo', 'salchichas?', 'salchichon', 'fuet', 'sobrasada', 'mortadella', 'frankfurters?', 'hot dogs?',
            'nduja', 'morcilla', 'butifarras?', 'botifarras?', 'pates?', 'foie', 'cooked ham', 'jamon cocido', 'jamon york', 'fiambres?',
            'embutidos?', 'burgers?', 'hamburguesas?', 'nuggets', 'croquetas?', 'albondigas?', 'meatballs?', 'surimi',
            // drinks with variable recipes
            'liqueurs?', 'licor(?:es)?', 'licors?', 'amaro', 'ratafia', 'cream liqueur', 'cocktails?', 'cocteles?',
            // flavourings, margarine, prepared dishes
            'flavou?rings?', 'flavou?rs?', 'aromas?', 'margarines?', 'margarina', 'ready meals?', 'platos? preparados?', 'gazpacho', 'gaspacho',
            'soups?', 'sopas?', 'cremas? de',
            // fillings, crusts, coatings
            'fillings?', 'rellenos?', 'farcits?', 'crusts?', 'cortezas?', 'crostas?', 'breaded', 'battered', 'batter', 'tempura',
            'rebozad[oa]s?', 'empanad[oa]s?', 'arrebossat', 'crispbreads?', 'pretzels?', 'bretzels?',
            // known liqueurs: egg, milk, nuts depending on the brand
            'amaretto', 'advocaat', 'baileys', 'frangelico', 'nocino', 'kahlua', 'limoncello', 'crema de orujo', 'pacharan', 'ponche',
            // known biscuits
            'petit[- ]?beurre', 'digestive', 'galletas? maria', 'maria biscuits?', 'speculoos', 'speculaas', 'biscoff', 'oreos?', 'shortbread',
            'amaretti', 'ladyfingers?', 'savoiardi', 'savoiardo', 'soletillas?', 'wafers?', 'barquillos?',
            // breakfast cereals
            'crispies', 'krispies', 'extrudates?', 'extruded cereals?', 'cornflakes', 'corn flakes', 'breakfast cereals?', 'cereales de desayuno',
        ],
        'cereal sin especificar: puede ser trigo, cebada, centeno o avena' => [
            'cereals?', 'cereales', '(?<!rice )(?<!corn )(?<!maize )(?<!oat )(?<!wheat )(?<!barley )(?<!rye )bran', 'salvado(?! de (?:arroz|maiz))',
        ],
        'nombre ambiguo: puede ser cosas distintas con alérgenos distintos' => [
            // Portuguese grape (wine) or Bastardo del Grappa (cheese)
            'bastardo',
        ],
        'manteca: puede ser mantequilla (leche, uso rioplatense) o manteca de cerdo' => ['manteca(?! de (?:cerdo|cacao|cacahuete|karite))'],
    ];

    /**
     * Processed products: an AI "no allergens" on one of these goes to
     * human review instead of being applied. Leaving an allergen out is the
     * dangerous mistake, and processing is where hidden ones come in (wheat
     * starch, milk powder carriers, soy in a seasoning). Only checked when
     * the proposal is empty — a processed product the AI does label
     * ("Egg yolk powder" → eggs) is applied as usual.
     */
    private const PROCESSED = [
        'extracts?', 'extractos?', 'extractes?', 'estratto', 'extraits?', 'powders?', 'powdered', 'polvo', 'pols', 'polvere', 'poudre',
        'concentrates?', 'concentrated', 'concentrados?', 'concentrat', 'concentre', 'flakes?', 'flaked', 'copos', 'flocs', 'flocons',
        'preparations?', 'prepared', 'preparad[oa]s?', 'preparats?', 'mix', 'mixes', 'mixture', 'blend', 'mezclas?', 'barreja',
        'seasonings?', 'sazonador(?:es)?', 'condimentos?', 'dried', 'dehydrated', 'deshidratad[oa]s?', 'deshidratats?', 'sec[oa]s?',
        'liofilizad[oa]s?', 'freeze dried', 'instant', 'instantane[oa]s?', 'hydroly[sz]ed', 'hydrolised', 'hydrolysates?',
        'hidrolizad[oa]s?', 'autoly[sz]ed', 'isolates?', 'starch(?:es)?', 'almidon(?:es)?', 'dextrins?', 'dextrinated', 'dextrinas?',
        'fib(?:re|er)s?', 'fibras?', 'proteins?', 'proteinas?', 'puffed', 'inflad[oa]s?', 'extruded', 'granules?', 'granulad[oa]s?',
        'coated', 'coating', 'recubiert[oa]s?',
    ];

    /** @var array<string, string> reason => compiled regex */
    private array $reviewRegex = [];

    /** @var array<string, string> allergen => compiled regex */
    private array $expectRegex = [];

    /** @var array<string, string> */
    private array $forbidRegex = [];

    private string $processedRegex;

    public function __construct()
    {
        foreach (self::EXPECT as $allergen => $keywords) {
            $this->expectRegex[$allergen] = '/\b(' . implode('|', $keywords) . ')\b/u';
        }
        foreach (self::FORBID as $allergen => $phrases) {
            $this->forbidRegex[$allergen] = '/\b(?:' . implode('|', $phrases) . ')\b/u';
        }
        foreach (self::REVIEW as $reason => $keywords) {
            $this->reviewRegex[$reason] = '/\b(?:' . implode('|', $keywords) . ')\b/u';
        }
        $this->processedRegex = '/\b(' . implode('|', self::PROCESSED) . ')\b/u';
    }

    /**
     * @return array{expected: list<string>, forbidden: list<string>, review: list<string>, processed: ?string, root: string}
     *   review    = reasons this name always needs a human look (empty for most)
     *   processed = the processing keyword found ("extract", "polvo"…), null when none
     *   root   = the first keyword matched (normalised), or the name's last word when none matched
     */
    public function evaluate(string $name): array
    {
        $normalized = self::normalize($name);

        $review = [];
        foreach ($this->reviewRegex as $reason => $regex) {
            if (preg_match($regex, $normalized)) {
                $review[] = $reason;
            }
        }

        $forbidden = [];
        foreach ($this->forbidRegex as $allergen => $regex) {
            if (preg_match($regex, $normalized)) {
                $forbidden[] = $allergen;
            }
        }

        $expected = [];
        $root = null;
        $rootOffset = PHP_INT_MAX;
        foreach ($this->expectRegex as $allergen => $regex) {
            if (in_array($allergen, $forbidden, true)) {
                continue;
            }
            if (preg_match($regex, $normalized, $m, PREG_OFFSET_CAPTURE)) {
                $expected[] = $allergen;
                if ($m[1][1] < $rootOffset) {
                    $rootOffset = $m[1][1];
                    $root = $m[1][0];
                }
            }
        }

        if ($root === null) {
            $words = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);
            $root = $words ? end($words) : '';
        }

        $processed = preg_match($this->processedRegex, $normalized, $m) ? $m[1] : null;

        return ['expected' => $expected, 'forbidden' => $forbidden, 'review' => $review, 'processed' => $processed, 'root' => rtrim($root, 's')];
    }

    /** Lowercase, accents stripped, everything that isn't a letter/digit turned into a single space. */
    public static function normalize(string $name): string
    {
        $name = mb_strtolower($name);
        $name = strtr($name, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c', 'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss', '·' => '',
        ]);
        $name = str_replace(["'s ", "’s "], 's ', $name);
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name);

        return trim($name);
    }
}
