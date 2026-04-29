<?php

/*
 * Browser-callable daily JavaScript quiz poster.
 *
 * Example:
 * https://www.kirupa.com/konvo_js_quiz_worker.php?key=YOUR_SECRET
 * https://www.kirupa.com/konvo_js_quiz_worker.php?key=YOUR_SECRET&dry_run=1
 * https://www.kirupa.com/konvo_js_quiz_worker.php?key=YOUR_SECRET&force=1
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('js_quiz_internal_error_out')) {
    function js_quiz_internal_error_out(string $message, int $status = 500): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($status);
        }
        echo json_encode(array('ok' => false, 'error' => $message), JSON_UNESCAPED_SLASHES);
        exit;
    }
}

set_exception_handler(static function (\Throwable $e): void {
    $where = basename((string)$e->getFile()) . ':' . (int)$e->getLine();
    $msg = trim((string)$e->getMessage());
    if ($msg === '') $msg = 'Unhandled exception';
    js_quiz_internal_error_out('JS quiz worker exception: ' . $msg . ' [' . $where . ']', 500);
});

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if (!is_array($err)) return;
    $type = (int)($err['type'] ?? 0);
    if (!in_array($type, array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) return;
    $msg = trim((string)($err['message'] ?? 'Fatal error'));
    $file = basename((string)($err['file'] ?? ''));
    $line = (int)($err['line'] ?? 0);
    js_quiz_internal_error_out('JS quiz worker fatal: ' . $msg . ' [' . $file . ':' . $line . ']', 500);
});

$signatureHelper = __DIR__ . '/konvo_signature_helper.php';
if (is_file($signatureHelper)) {
    require_once $signatureHelper;
}
$konvoModelRouter = __DIR__ . '/konvo_model_router.php';
if (is_file($konvoModelRouter)) {
    require_once $konvoModelRouter;
}
if (!function_exists('konvo_model_for_task')) {
    function konvo_model_for_task(string $task, array $ctx = array()): string
    {
        return 'gpt-5.2';
    }
}

if (!defined('KONVO_BASE_URL')) define('KONVO_BASE_URL', 'https://forum.kirupa.com');
if (!defined('KONVO_API_KEY')) define('KONVO_API_KEY', trim((string)getenv('DISCOURSE_API_KEY')));
if (!defined('KONVO_SECRET')) define('KONVO_SECRET', trim((string)getenv('DISCOURSE_WEBHOOK_SECRET')));
if (!defined('KONVO_OPENAI_API_KEY')) define('KONVO_OPENAI_API_KEY', trim((string)getenv('OPENAI_API_KEY')));
if (!defined('KONVO_CATEGORY_ID')) define('KONVO_CATEGORY_ID', 42);
if (!defined('KONVO_TZ')) define('KONVO_TZ', trim((string)(getenv('KONVO_TIMEZONE') ?: 'America/Los_Angeles')));

@date_default_timezone_set(KONVO_TZ);

$bots = array(
    array('username' => 'BayMax', 'name' => 'BayMax'),
    array('username' => 'vaultboy', 'name' => 'VaultBoy'),
    array('username' => 'MechaPrime', 'name' => 'MechaPrime'),
    array('username' => 'yoshiii', 'name' => 'Yoshiii'),
    array('username' => 'bobamilk', 'name' => 'BobaMilk'),
    array('username' => 'wafflefries', 'name' => 'WaffleFries'),
    array('username' => 'quelly', 'name' => 'Quelly'),
    array('username' => 'sora', 'name' => 'Sora'),
    array('username' => 'sarah_connor', 'name' => 'Sarah'),
    array('username' => 'ellen1979', 'name' => 'Ellen'),
    array('username' => 'arthurdent', 'name' => 'Arthur'),
    array('username' => 'hariseldon', 'name' => 'Hari'),
);

$quizzes = array(
    array(
        'title' => 'Microtasks vs timers output order',
        'difficulty' => 'Hard',
        'prompt' => 'What is the exact console output order?',
        'code' => "console.log('1');\nsetTimeout(() => console.log('2'), 0);\nPromise.resolve().then(() => console.log('3'));\nqueueMicrotask(() => console.log('4'));\nconsole.log('5');",
        'options' => array(
            '1, 5, 3, 4, 2',
            '1, 3, 4, 5, 2',
            '1, 5, 4, 3, 2',
            '1, 2, 5, 3, 4',
        ),
        'answer_index' => 1,
        'explanation' => 'Synchronous logs run first (`1`, then `5`). Microtasks from `Promise.then` and `queueMicrotask` run before timers, and they execute in queue order (`3`, then `4`). `setTimeout(..., 0)` runs afterward, so `2` is last.',
    ),
    array(
        'title' => 'Temporal dead zone edge case',
        'difficulty' => 'Medium',
        'prompt' => 'What happens when this runs?',
        'code' => "{\n  console.log(a);\n  let a = 10;\n}",
        'options' => array(
            'Logs undefined, then 10',
            'Logs null, then 10',
            'Throws ReferenceError before any log',
            'Throws SyntaxError at parse time',
        ),
        'answer_index' => 3,
        'explanation' => '`let a` is in the temporal dead zone from block start until its declaration line executes. Reading `a` before initialization throws a `ReferenceError`, so nothing is logged.',
    ),
    array(
        'title' => 'Method extraction and this binding',
        'difficulty' => 'Medium',
        'prompt' => 'What does this print?',
        'code' => "const obj = {\n  x: 41,\n  getX() { return this.x; }\n};\n\nconst fn = obj.getX;\nconsole.log(fn(), obj.getX.call({ x: 99 }));",
        'options' => array(
            '41 99',
            'undefined 99',
            '99 99',
            'TypeError is thrown',
        ),
        'answer_index' => 2,
        'explanation' => 'Extracting `obj.getX` into `fn` loses the object receiver. In this non-strict-style call, `fn()` reads `this.x` from the global object, which is typically `undefined`. The explicit `.call({ x: 99 })` binds `this`, so that part returns `99`.',
    ),
    array(
        'title' => 'Array map with implicit undefined',
        'difficulty' => 'Easy',
        'prompt' => 'What is the output?',
        'code' => "const out = [1, 2, 3].map((n) => {\n  if (n % 2) return;\n  return n * 2;\n});\n\nconsole.log(out.join(','));",
        'options' => array(
            ',4,',
            'undefined,4,undefined',
            '4',
            '2,4,6',
        ),
        'answer_index' => 1,
        'explanation' => 'For odd numbers, the callback returns `undefined`. So `out` becomes `[undefined, 4, undefined]`. `join(\',\')` turns `undefined` entries into empty strings, resulting in `,4,`.',
    ),
    array(
        'title' => 'Async return in finally',
        'difficulty' => 'Easy',
        'prompt' => 'What gets logged?',
        'code' => "async function f() {\n  try {\n    return 'A';\n  } finally {\n    return 'B';\n  }\n}\n\nf().then(console.log);",
        'options' => array(
            'A',
            'B',
            'A then B',
            'Unhandled promise rejection',
        ),
        'answer_index' => 2,
        'explanation' => 'A `return` in `finally` overrides a previous `return` from `try`. The promise resolves to `B`, so the log is just `B`.',
    ),
    array(
        'title' => 'Optional chaining with side effects',
        'difficulty' => 'Medium',
        'prompt' => 'What is printed by the final console.log?',
        'code' => "const x = {\n  value: 0,\n  inc() {\n    this.value++;\n    return this.value;\n  }\n};\n\nconst a = x.inc?.();\nconst b = (x.missing?.()) ?? 42;\nconsole.log(a, b, x.value);",
        'options' => array(
            '1 42 1',
            '1 undefined 1',
            'undefined 42 0',
            'TypeError is thrown',
        ),
        'answer_index' => 1,
        'explanation' => '`x.inc?.()` exists and runs, so `a` is `1` and `x.value` becomes `1`. `x.missing?.()` short-circuits to `undefined`, then nullish coalescing gives `42`. Final output is `1 42 1`.',
    ),
    array(
        'title' => 'Var vs let in timer loops',
        'difficulty' => 'Easy',
        'prompt' => 'What sequence is logged?',
        'code' => "for (var i = 0; i < 3; i++) {\n  setTimeout(() => console.log(i), 0);\n}\nfor (let j = 0; j < 3; j++) {\n  setTimeout(() => console.log(j), 0);\n}",
        'options' => array(
            '3 3 3 0 1 2',
            '0 1 2 0 1 2',
            '3 3 3 3 3 3',
            '0 1 2 3 3 3',
        ),
        'answer_index' => 1,
        'explanation' => '`var i` is function-scoped, so all first-loop callbacks share one binding and see `i === 3` when they run. `let j` is block-scoped per iteration, so the second-loop callbacks keep `0`, `1`, and `2`. Timers execute in scheduling order, so the three `3`s come first.',
    ),
);

$quizzes = array_merge($quizzes, array(
    array(
        'title' => 'Numeric sort comparator trap',
        'difficulty' => 'Easy',
        'theme' => 'arrays',
        'language' => 'js',
        'snippet_size' => 'tiny',
        'source_name' => 'MDN Array.sort',
        'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Array/sort',
        'prompt' => 'What is logged?',
        'code' => "const nums = [10, 2, 1];\nnums.sort();\nconsole.log(nums.join(','));",
        'options' => array('1,2,10', '10,2,1', '1,10,2', '2,1,10'),
        'answer_index' => 3,
        'explanation' => 'Without a comparator, sort converts values to strings and compares lexicographically.',
    ),
    array(
        'title' => 'Object key coercion collision',
        'difficulty' => 'Medium',
        'theme' => 'objects',
        'language' => 'js',
        'snippet_size' => 'short',
        'source_name' => 'MDN Property accessors',
        'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Operators/Property_accessors',
        'prompt' => 'What does this print?',
        'code' => "const a = {};\nconst b = {};\nconst obj = {};\nobj[a] = 'first';\nobj[b] = 'second';\nconsole.log(Object.keys(obj).length, obj[a]);",
        'options' => array('2 first', '2 second', '1 first', '1 second'),
        'answer_index' => 4,
        'explanation' => 'Object keys are strings here, so both object keys coerce to \"[object Object]\" and collide.',
    ),
    array(
        'title' => 'Sparse array mapping holes',
        'difficulty' => 'Medium',
        'theme' => 'arrays',
        'language' => 'js',
        'snippet_size' => 'short',
        'source_name' => 'MDN Array.map',
        'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Array/map',
        'prompt' => 'What is the final output?',
        'code' => "const a = new Array(3);\nconst b = a.map(() => 1);\nconsole.log(a.length, b.length, 0 in b, b.join('-'));",
        'options' => array('3 3 true 1-1-1', '3 3 false --', '0 0 false ', '3 0 false '),
        'answer_index' => 2,
        'explanation' => 'Holes remain holes through map callbacks, so indices are still empty slots.',
    ),
    array(
        'title' => 'Destructuring defaults and null',
        'difficulty' => 'Easy',
        'theme' => 'syntax',
        'language' => 'js',
        'snippet_size' => 'tiny',
        'source_name' => 'MDN Destructuring',
        'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Operators/Destructuring',
        'prompt' => 'What gets logged?',
        'code' => "const { x = 5 } = { x: null };\nconsole.log(x);",
        'options' => array('5', 'null', 'undefined', 'TypeError'),
        'answer_index' => 2,
        'explanation' => 'Destructuring defaults apply only when the value is undefined, not null.',
    ),
    array(
        'title' => 'Regex global test state leak',
        'difficulty' => 'Hard',
        'theme' => 'regex',
        'language' => 'js',
        'snippet_size' => 'short',
        'source_name' => 'MDN RegExp.test',
        'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/RegExp/test',
        'prompt' => 'What is printed?',
        'code' => "const re = /a/g;\nconsole.log(re.test('a'), re.test('a'), re.test('a'));\nconsole.log(re.lastIndex);",
        'options' => array('true true true / 0', 'true false true / 1', 'true false true / 0', 'true false false / 0'),
        'answer_index' => 3,
        'explanation' => 'Global regex advances lastIndex across calls and resets when a test fails at end of input.',
    ),
    array(
        'title' => 'Shared reference mutation surprise',
        'difficulty' => 'Easy',
        'theme' => 'immutability',
        'language' => 'js',
        'snippet_size' => 'short',
        'source_name' => 'kirupa Objects and References',
        'source_url' => 'https://www.kirupa.com/html5/working_with_objects_in_javascript.htm',
        'prompt' => 'What does this log?',
        'code' => "const base = { count: 0 };\nconst arr = Array(3).fill(base);\narr[1].count = 7;\nconsole.log(arr[0].count, arr[2].count);",
        'options' => array('0 0', '7 0', '0 7', '7 7'),
        'answer_index' => 4,
        'explanation' => 'Array.fill with an object repeats the same reference for every slot.',
    ),
    array(
        'title' => 'Set identity with object literals',
        'difficulty' => 'Medium',
        'theme' => 'collections',
        'language' => 'js',
        'snippet_size' => 'tiny',
        'source_name' => 'MDN Set',
        'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Set',
        'prompt' => 'What is the output?',
        'code' => "const s = new Set();\ns.add({ id: 1 });\ns.add({ id: 1 });\nconsole.log(s.size);",
        'options' => array('1', '2', '0', 'TypeError'),
        'answer_index' => 2,
        'explanation' => 'Objects are unique by reference, so two separate literals count as different entries.',
    ),
    array(
        'title' => 'Fetch error handling misconception',
        'difficulty' => 'Hard',
        'theme' => 'network',
        'language' => 'js',
        'snippet_size' => 'medium',
        'source_name' => 'MDN Fetch API',
        'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API/Using_Fetch',
        'prompt' => 'Which branch runs when the server returns 404?',
        'code' => "fetch('/missing')\n  .then((r) => {\n    if (!r.ok) return 'bad';\n    return 'good';\n  })\n  .catch(() => 'caught')\n  .then((v) => console.log(v));",
        'options' => array('good', 'bad', 'caught', 'nothing, promise rejects silently'),
        'answer_index' => 2,
        'explanation' => 'fetch resolves for HTTP errors; catch is for network failures or thrown errors.',
    ),
    array(
        'title' => 'Prototype method and detached call',
        'difficulty' => 'Hard',
        'theme' => 'prototypes',
        'language' => 'js',
        'snippet_size' => 'medium',
        'source_name' => 'MDN this',
        'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Operators/this',
        'prompt' => 'What is logged in strict mode?',
        'code' => "\"use strict\";\nclass Counter {\n  constructor() { this.n = 1; }\n  inc() { this.n += 1; return this.n; }\n}\nconst c = new Counter();\nconst fn = c.inc;\ntry {\n  console.log(fn());\n} catch (e) {\n  console.log(e.name);\n}",
        'options' => array('2', 'NaN', 'TypeError', 'ReferenceError'),
        'answer_index' => 3,
        'explanation' => 'Detached method call has undefined this in strict mode, so accessing this.n throws.',
    ),
    array(
        'title' => 'Node list snapshot vs live collection',
        'difficulty' => 'Hard',
        'theme' => 'dom',
        'language' => 'js',
        'snippet_size' => 'long',
        'source_name' => 'MDN querySelectorAll',
        'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/API/Document/querySelectorAll',
        'prompt' => 'Assuming this runs in a browser, what logs?',
        'code' => "const ul = document.createElement('ul');\nul.innerHTML = '<li>A</li><li>B</li>';\ndocument.body.appendChild(ul);\n\nconst staticList = ul.querySelectorAll('li');\nconst liveList = ul.getElementsByTagName('li');\n\nconst li = document.createElement('li');\nli.textContent = 'C';\nul.appendChild(li);\n\nconsole.log(staticList.length, liveList.length);",
        'options' => array('2 2', '3 3', '2 3', '3 2'),
        'answer_index' => 3,
        'explanation' => 'querySelectorAll gives a static snapshot, while getElementsByTagName is live.',
    ),
    array(
        'title' => 'Reduce accumulator initialization edge case',
        'difficulty' => 'Medium',
        'theme' => 'arrays',
        'language' => 'js',
        'snippet_size' => 'short',
        'source_name' => 'MDN Array.reduce',
        'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Array/reduce',
        'prompt' => 'What happens here?',
        'code' => "const arr = [];\nconst total = arr.reduce((sum, n) => sum + n);\nconsole.log(total);",
        'options' => array('0', 'undefined', 'NaN', 'TypeError'),
        'answer_index' => 4,
        'explanation' => 'reduce on an empty array without an initial value throws TypeError.',
    ),
    array(
        'title' => 'Map key identity with NaN',
        'difficulty' => 'Extremely Easy',
        'theme' => 'collections',
        'language' => 'js',
        'snippet_size' => 'tiny',
        'source_name' => 'MDN Map',
        'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Map',
        'prompt' => 'What is printed?',
        'code' => "const m = new Map();\nm.set(NaN, 'first');\nm.set(NaN, 'second');\nconsole.log(m.size, m.get(NaN));",
        'options' => array('2 first', '2 second', '1 second', '1 first'),
        'answer_index' => 3,
        'explanation' => 'Map treats NaN keys as the same key for equality.',
    ),
    array(
        'title' => 'Generator consumption order puzzle',
        'difficulty' => 'Extremely Hard',
        'theme' => 'iterators',
        'language' => 'js',
        'snippet_size' => 'long',
        'source_name' => 'MDN Generators',
        'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Generator',
        'prompt' => 'What is logged?',
        'code' => "function* g() {\n  yield 1;\n  yield 2;\n  return 3;\n}\nconst it = g();\nconst a = [...it];\nconst b = it.next();\nconsole.log(a.join(','), b.value, b.done);",
        'options' => array('1,2,3 3 true', '1,2 undefined true', '1,2 3 false', '1,2 undefined false'),
        'answer_index' => 2,
        'explanation' => 'Spread consumes yielded values only and exhausts the iterator; next then returns done true with undefined value.',
    ),
));

function out_json($code, $data)
{
    if (function_exists('http_response_code')) {
        http_response_code((int)$code);
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function safe_hash_equals($a, $b)
{
    $a = (string)$a;
    $b = (string)$b;
    if (function_exists('hash_equals')) {
        return hash_equals($a, $b);
    }
    if (strlen($a) !== strlen($b)) {
        return false;
    }
    $res = 0;
    $len = strlen($a);
    for ($i = 0; $i < $len; $i++) {
        $res |= ord($a[$i]) ^ ord($b[$i]);
    }
    return $res === 0;
}

function js_quiz_state_path()
{
    $dir = __DIR__ . '/.konvo_state';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/js_quiz_daily_state.json';
}

function js_quiz_load_state()
{
    $path = js_quiz_state_path();
    if (!is_file($path)) return array();
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return array();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function js_quiz_save_state($state)
{
    if (!is_array($state)) return;
    @file_put_contents(js_quiz_state_path(), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function js_quiz_openai_json(array $payload): array
{
    if (KONVO_OPENAI_API_KEY === '') {
        return array('ok' => false, 'error' => 'OPENAI_API_KEY missing.');
    }
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'error' => 'curl_init unavailable.');
    }
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 70,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . KONVO_OPENAI_API_KEY,
        ),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ));
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $err !== '') {
        return array('ok' => false, 'error' => ($err !== '' ? $err : 'OpenAI request failed.'));
    }
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return array('ok' => false, 'error' => 'OpenAI JSON decode failed.');
    }
    if ($status < 200 || $status >= 300) {
        $msg = trim((string)($decoded['error']['message'] ?? ''));
        return array('ok' => false, 'error' => ($msg !== '' ? $msg : 'OpenAI returned status ' . $status));
    }
    return array('ok' => true, 'body' => $decoded);
}

function js_quiz_extract_json_object(string $content): ?array
{
    $content = trim($content);
    if ($content === '') return null;

    $decoded = json_decode($content, true);
    if (is_array($decoded)) return $decoded;

    $start = strpos($content, '{');
    $end = strrpos($content, '}');
    if ($start === false || $end === false || $end <= $start) return null;
    $slice = substr($content, (int)$start, (int)($end - $start + 1));
    $decoded = json_decode((string)$slice, true);
    return is_array($decoded) ? $decoded : null;
}

function js_quiz_norm_lang(string $lang): string
{
    $v = strtolower(trim($lang));
    if ($v === 'js' || $v === 'javascript' || $v === 'node') return 'js';
    if ($v === 'ts' || $v === 'typescript') return 'ts';
    if ($v === 'html') return 'html';
    if ($v === 'css') return 'css';
    return 'js';
}

function js_quiz_recent_vals(array $state, string $key, int $max = 12): array
{
    $out = array();
    if (isset($state[$key]) && is_array($state[$key])) {
        foreach ($state[$key] as $v) {
            $s = strtolower(trim((string)$v));
            if ($s === '') continue;
            if (!in_array($s, $out, true)) $out[] = $s;
            if (count($out) >= $max) break;
        }
    }
    return $out;
}

function js_quiz_source_seeds(): array
{
    return array(
        array('theme' => 'arrays', 'source_name' => 'MDN Array docs', 'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Array'),
        array('theme' => 'objects', 'source_name' => 'MDN Object docs', 'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Object'),
        array('theme' => 'dom', 'source_name' => 'MDN DOM API', 'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/API/Document_Object_Model'),
        array('theme' => 'regex', 'source_name' => 'MDN RegExp', 'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Guide/Regular_expressions'),
        array('theme' => 'iterators', 'source_name' => 'MDN Iteration protocols', 'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Iteration_protocols'),
        array('theme' => 'network', 'source_name' => 'MDN Fetch API', 'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API'),
        array('theme' => 'immutability', 'source_name' => 'kirupa JavaScript Objects', 'source_url' => 'https://www.kirupa.com/html5/working_with_objects_in_javascript.htm'),
        array('theme' => 'scope', 'source_name' => 'kirupa JS Scope', 'source_url' => 'https://www.kirupa.com/html5/understanding_javascript_scope.htm'),
        array('theme' => 'events', 'source_name' => 'kirupa Event Handling', 'source_url' => 'https://www.kirupa.com/html5/introduction_to_javascript_events.htm'),
        array('theme' => 'canvas', 'source_name' => 'kirupa Canvas Basics', 'source_url' => 'https://www.kirupa.com/html5/getting_started_with_canvas.htm'),
        array('theme' => 'collections', 'source_name' => 'MDN Map and Set', 'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Map'),
        array('theme' => 'strings', 'source_name' => 'MDN String docs', 'source_url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/String'),
    );
}

function js_quiz_pick_seed(array $state): array
{
    $seeds = js_quiz_source_seeds();
    $recentThemes = js_quiz_recent_vals($state, 'recent_themes', 8);
    $pool = array();
    foreach ($seeds as $s) {
        $k = strtolower(trim((string)($s['theme'] ?? '')));
        if ($k !== '' && !in_array($k, $recentThemes, true)) $pool[] = $s;
    }
    if ($pool === array()) $pool = $seeds;
    return $pool[mt_rand(0, count($pool) - 1)];
}

function js_quiz_pick_target_difficulty(array $state): string
{
    $recent = js_quiz_recent_vals($state, 'recent_difficulties', 5);
    $choices = array('Extremely Easy', 'Easy', 'Medium', 'Hard', 'Extremely Hard');
    $pool = array();
    foreach ($choices as $d) {
        if (!in_array(strtolower($d), $recent, true)) $pool[] = $d;
    }
    if ($pool === array()) $pool = $choices;
    return $pool[mt_rand(0, count($pool) - 1)];
}

function js_quiz_pick_target_size(array $state): string
{
    $recent = js_quiz_recent_vals($state, 'recent_sizes', 5);
    $choices = array('tiny', 'short', 'medium', 'long');
    $pool = array();
    foreach ($choices as $v) {
        if (!in_array(strtolower($v), $recent, true)) $pool[] = $v;
    }
    if ($pool === array()) $pool = $choices;
    return $pool[mt_rand(0, count($pool) - 1)];
}

function js_quiz_validate_generated(array $q): bool
{
    $title = trim((string)($q['title'] ?? ''));
    $prompt = trim((string)($q['prompt'] ?? ''));
    $code = rtrim((string)($q['code'] ?? ''));
    $opts = isset($q['options']) && is_array($q['options']) ? array_values($q['options']) : array();
    $ai = (int)($q['answer_index'] ?? 0);
    $exp = trim((string)($q['explanation'] ?? ''));
    if ($title === '' || $prompt === '' || $code === '' || $exp === '') return false;
    if (count($opts) !== 4) return false;
    foreach ($opts as $o) {
        if (trim((string)$o) === '') return false;
    }
    if ($ai < 1 || $ai > 4) return false;
    return true;
}

function js_quiz_generate_with_llm(array $state): array
{
    if (KONVO_OPENAI_API_KEY === '') {
        return array('ok' => false, 'error' => 'OPENAI_API_KEY missing');
    }

    $seed = js_quiz_pick_seed($state);
    $targetDifficulty = js_quiz_pick_target_difficulty($state);
    $targetSize = js_quiz_pick_target_size($state);
    $recentTitles = js_quiz_recent_vals($state, 'recent_titles', 16);
    $recentThemes = js_quiz_recent_vals($state, 'recent_themes', 12);

    $avoidTitles = $recentTitles === array() ? '(none)' : implode('; ', $recentTitles);
    $avoidThemes = $recentThemes === array() ? '(none)' : implode(', ', $recentThemes);
    $sourceName = (string)($seed['source_name'] ?? 'MDN');
    $sourceUrl = (string)($seed['source_url'] ?? 'https://developer.mozilla.org/');
    $theme = (string)($seed['theme'] ?? 'javascript');

    $system = 'You generate JavaScript quiz items for a forum. Return strict JSON only.';
    $user = "Generate one JS quiz item.\n"
        . "Use this source as conceptual inspiration: {$sourceName} ({$sourceUrl}). Do not copy text verbatim.\n"
        . "Primary theme: {$theme}\n"
        . "Target difficulty: {$targetDifficulty}\n"
        . "Target snippet size: {$targetSize}\n"
        . "Recent titles to avoid: {$avoidTitles}\n"
        . "Recent themes to avoid: {$avoidThemes}\n\n"
        . "Hard requirements:\n"
        . "- Return JSON with keys: title, difficulty, theme, source_name, source_url, language, snippet_size, prompt, code, options, answer_index, explanation.\n"
        . "- language must be one of: js, ts, html, css.\n"
        . "- options must be exactly 4 plausible choices.\n"
        . "- answer_index must be 1..4.\n"
        . "- prompt must be short and clear.\n"
        . "- no timer/async/debounce topics unless absolutely necessary; prefer broad variety.\n"
        . "- code should be practical and self-contained.\n"
        . "- no markdown fences, JSON only.";

    $payload = array(
        'model' => konvo_model_for_task('deep_question', array('technical' => true)),
        'temperature' => 0.95,
        'max_tokens' => 1200,
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $user),
        ),
    );
    $res = js_quiz_openai_json($payload);
    if (!$res['ok']) return $res;

    $content = trim((string)($res['body']['choices'][0]['message']['content'] ?? ''));
    $obj = js_quiz_extract_json_object($content);
    if (!is_array($obj)) {
        return array('ok' => false, 'error' => 'OpenAI response format error');
    }

    $obj['difficulty'] = trim((string)($obj['difficulty'] ?? $targetDifficulty));
    $obj['theme'] = trim((string)($obj['theme'] ?? $theme));
    $obj['source_name'] = trim((string)($obj['source_name'] ?? $sourceName));
    $obj['source_url'] = trim((string)($obj['source_url'] ?? $sourceUrl));
    $obj['snippet_size'] = strtolower(trim((string)($obj['snippet_size'] ?? $targetSize)));
    $obj['language'] = js_quiz_norm_lang((string)($obj['language'] ?? 'js'));
    if (!js_quiz_validate_generated($obj)) {
        return array('ok' => false, 'error' => 'OpenAI quiz validation failed');
    }
    return array('ok' => true, 'quiz' => $obj);
}

function js_quiz_enrich_quiz(array $quiz): array
{
    if (!isset($quiz['language']) || trim((string)$quiz['language']) === '') $quiz['language'] = 'js';
    $quiz['language'] = js_quiz_norm_lang((string)$quiz['language']);
    if (!isset($quiz['theme']) || trim((string)$quiz['theme']) === '') {
        $blob = strtolower((string)($quiz['title'] ?? '') . "\n" . (string)($quiz['prompt'] ?? '') . "\n" . (string)($quiz['code'] ?? ''));
        if (preg_match('/\b(dom|document|queryselector|element|classlist)\b/', $blob)) $quiz['theme'] = 'dom';
        elseif (preg_match('/\b(regex|regexp)\b/', $blob)) $quiz['theme'] = 'regex';
        elseif (preg_match('/\b(map|set|weakmap|weakset)\b/', $blob)) $quiz['theme'] = 'collections';
        elseif (preg_match('/\b(fetch|request|response|http)\b/', $blob)) $quiz['theme'] = 'network';
        elseif (preg_match('/\b(array|reduce|filter|map\(|sort\(|find\()\b/', $blob)) $quiz['theme'] = 'arrays';
        elseif (preg_match('/\b(object|prototype|this|class)\b/', $blob)) $quiz['theme'] = 'objects';
        else $quiz['theme'] = 'javascript';
    }
    if (!isset($quiz['snippet_size']) || trim((string)$quiz['snippet_size']) === '') {
        $lines = substr_count((string)($quiz['code'] ?? ''), "\n") + 1;
        if ($lines <= 4) $quiz['snippet_size'] = 'tiny';
        elseif ($lines <= 8) $quiz['snippet_size'] = 'short';
        elseif ($lines <= 15) $quiz['snippet_size'] = 'medium';
        else $quiz['snippet_size'] = 'long';
    }
    if (!isset($quiz['source_name'])) $quiz['source_name'] = '';
    if (!isset($quiz['source_url'])) $quiz['source_url'] = '';
    return $quiz;
}

function js_quiz_pick_bot($bots)
{
    if (!is_array($bots) || count($bots) === 0) {
        return array('username' => 'BayMax', 'name' => 'BayMax');
    }
    shuffle($bots);
    return $bots[0];
}

function js_quiz_pick_quiz($quizzes, $state)
{
    $generated = js_quiz_generate_with_llm(is_array($state) ? $state : array());
    if (!empty($generated['ok']) && isset($generated['quiz']) && is_array($generated['quiz'])) {
        $q = js_quiz_enrich_quiz($generated['quiz']);
        $q['_origin'] = 'llm';
        return $q;
    }

    if (!is_array($quizzes) || count($quizzes) === 0) {
        $fallback = array(
            'title' => 'Closure and event loop output',
            'difficulty' => 'Medium',
            'prompt' => 'What is the output?',
            'code' => "console.log('fallback');",
            'options' => array('fallback', 'undefined', 'null', 'TypeError'),
            'answer_index' => 1,
            'explanation' => 'Only one statement runs, so the console prints fallback.',
        );
        $fallback = js_quiz_enrich_quiz($fallback);
        $fallback['_origin'] = 'fallback';
        return $fallback;
    }

    $recent = array();
    if (is_array($state) && isset($state['recent_titles']) && is_array($state['recent_titles'])) {
        foreach ($state['recent_titles'] as $t) {
            $k = strtolower(trim((string)$t));
            if ($k !== '') $recent[$k] = true;
        }
    }

    $pool = array();
    foreach ($quizzes as $q) {
        $k = strtolower(trim((string)($q['title'] ?? '')));
        if ($k === '' || isset($recent[$k])) continue;
        $pool[] = $q;
    }
    if ($pool === array()) {
        $pool = $quizzes;
    }

    $recentThemes = js_quiz_recent_vals(is_array($state) ? $state : array(), 'recent_themes', 8);
    $recentDiff = js_quiz_recent_vals(is_array($state) ? $state : array(), 'recent_difficulties', 4);
    $filtered = array();
    foreach ($pool as $q) {
        $qq = js_quiz_enrich_quiz(is_array($q) ? $q : array());
        $runtimeBlob = strtolower((string)($qq['title'] ?? '') . "\n" . (string)($qq['prompt'] ?? '') . "\n" . (string)($qq['code'] ?? ''));
        $isRuntimeHeavy = (bool)preg_match('/\b(settimeout|setinterval|debounce|throttle|queuemicrotask|microtask|promise|async|await)\b/', $runtimeBlob);
        if ($isRuntimeHeavy && mt_rand(1, 100) <= 88) {
            continue;
        }
        $theme = strtolower(trim((string)($qq['theme'] ?? '')));
        $diff = strtolower(trim((string)($qq['difficulty'] ?? '')));
        if ($theme !== '' && in_array($theme, $recentThemes, true) && mt_rand(1, 100) <= 75) {
            continue;
        }
        if ($diff !== '' && in_array($diff, $recentDiff, true) && mt_rand(1, 100) <= 60) {
            continue;
        }
        $filtered[] = $qq;
    }
    if ($filtered === array()) {
        $filtered = array_map('js_quiz_enrich_quiz', $pool);
    }
    $targetDifficulty = js_quiz_pick_target_difficulty(is_array($state) ? $state : array());
    $targetDifficultyNorm = js_quiz_normalize_difficulty($targetDifficulty);
    if ($targetDifficultyNorm === '') $targetDifficultyNorm = 'Medium';
    $targetSize = js_quiz_pick_target_size(is_array($state) ? $state : array());

    $focused = array();
    foreach ($filtered as $qq) {
        $qqDiffNorm = js_quiz_normalize_difficulty((string)($qq['difficulty'] ?? ''));
        $qqSize = strtolower(trim((string)($qq['snippet_size'] ?? '')));
        if ($qqDiffNorm === $targetDifficultyNorm && $qqSize === $targetSize) {
            $focused[] = $qq;
        }
    }
    if ($focused === array()) {
        foreach ($filtered as $qq) {
            $qqDiffNorm = js_quiz_normalize_difficulty((string)($qq['difficulty'] ?? ''));
            if ($qqDiffNorm === $targetDifficultyNorm) $focused[] = $qq;
        }
    }
    if ($focused === array()) {
        foreach ($filtered as $qq) {
            $qqSize = strtolower(trim((string)($qq['snippet_size'] ?? '')));
            if ($qqSize === $targetSize) $focused[] = $qq;
        }
    }
    if ($focused === array()) $focused = $filtered;

    $picked = $focused[mt_rand(0, count($focused) - 1)];
    $picked['_origin'] = 'curated';
    $picked['_target_difficulty'] = $targetDifficulty;
    $picked['_target_size'] = $targetSize;
    return $picked;
}

function js_quiz_normalize_difficulty($difficulty)
{
    $d = strtolower(trim((string)$difficulty));
    if ($d === 'easy' || $d === 'beginner' || $d === 'basic' || $d === 'extremely easy' || $d === 'very easy') return 'Easy';
    if ($d === 'medium' || $d === 'intermediate' || $d === 'mid') return 'Medium';
    if ($d === 'hard' || $d === 'advanced' || $d === 'expert' || $d === 'extremely hard' || $d === 'very hard') return 'Hard';
    return '';
}

function js_quiz_infer_difficulty($quiz)
{
    $explicit = js_quiz_normalize_difficulty((string)($quiz['difficulty'] ?? ''));
    if ($explicit !== '') return $explicit;

    $code = strtolower((string)($quiz['code'] ?? ''));
    $prompt = strtolower((string)($quiz['prompt'] ?? ''));
    $score = 0;

    if (preg_match('/\b(async|await|promise|queuemicrotask|microtask|finally)\b/', $code)) $score += 2;
    if (preg_match('/\b(this|call\(|bind\(|prototype)\b/', $code)) $score += 1;
    if (preg_match('/\?\?|\?\./', $code)) $score += 1;
    if (preg_match('/\b(let|const|var)\b/', $code) && preg_match('/for\s*\(/', $code)) $score += 1;
    if (substr_count($code, "\n") >= 8) $score += 1;
    if (preg_match('/\bexact|sequence|order\b/', $prompt)) $score += 1;

    if ($score <= 1) return 'Easy';
    if ($score <= 3) return 'Medium';
    return 'Hard';
}

function js_quiz_title($title, $difficulty = '')
{
    $t = trim((string)$title);
    if ($t === '') $t = 'Output challenge';
    $t = preg_replace('/^\s*JS\s*Quiz\s*:\s*/i', '', $t) ?? $t;
    $t = preg_replace('/^\s*(Easy|Medium|Hard)\s*:\s*/i', '', $t) ?? $t;
    $t = trim((string)$t);
    if ($t === '') $t = 'Output challenge';

    $level = js_quiz_normalize_difficulty($difficulty);
    if ($level === '') $level = 'Medium';
    return 'JS Quiz: ' . $level . ': ' . $t;
}

function normalize_signature_once($text, $name)
{
    $candidates = function_exists('konvo_signature_name_candidates')
        ? konvo_signature_name_candidates((string)$name)
        : array((string)$name);
    if (!is_array($candidates) || count($candidates) === 0) {
        $candidates = array((string)$name);
    }

    $lines = preg_split('/\R/', trim((string)$text));
    if (!is_array($lines)) $lines = array();
    while (!empty($lines)) {
        $last = trim((string)end($lines));
        $matched = false;
        foreach ($candidates as $candidate) {
            if (preg_match('/^' . preg_quote((string)$candidate, '/') . '\\.?$/i', $last)) {
                $matched = true;
                break;
            }
        }
        if ($last === '' || $matched) {
            array_pop($lines);
            continue;
        }
        break;
    }
    $body = trim(implode("\n", $lines));
    foreach ($candidates as $candidate) {
        $body = preg_replace('/\s+' . preg_quote((string)$candidate, '/') . '\\.?$/i', '', (string)$body);
    }
    $body = trim((string)$body);
    if ($body === '') return '';
    return $body;
}

function js_quiz_build_raw($quiz, $signature)
{
    $prompt = trim((string)($quiz['prompt'] ?? 'What is the correct output?'));
    if ($prompt === '') $prompt = 'What is the correct output?';
    $code = rtrim((string)($quiz['code'] ?? "console.log('hello');"));
    if ($code === '') $code = "console.log('hello');";
    $lang = js_quiz_norm_lang((string)($quiz['language'] ?? 'js'));
    $options = isset($quiz['options']) && is_array($quiz['options']) ? $quiz['options'] : array();

    if (count($options) < 4) {
        $options = array('Option 1', 'Option 2', 'Option 3', 'Option 4');
    }
    $options = array_slice(array_values($options), 0, 4);

    $lines = array();
    $lines[] = $prompt;
    $lines[] = '';
    $lines[] = '```' . $lang;
    $lines[] = $code;
    $lines[] = '```';
    $lines[] = '';
    $lines[] = '[poll type=regular results=always public=true chartType=bar]';
    foreach ($options as $opt) {
        $lines[] = '* ' . trim((string)$opt);
    }
    $lines[] = '[/poll]';

    return normalize_signature_once(implode("\n", $lines), $signature);
}

function js_quiz_pending_item($quiz, $bot, $topicId, $quizPostNumber, $topicTitle)
{
    $options = isset($quiz['options']) && is_array($quiz['options']) ? array_values($quiz['options']) : array();
    if (count($options) < 4) {
        $options = array('Option 1', 'Option 2', 'Option 3', 'Option 4');
    }
    $options = array_slice($options, 0, 4);

    $answerIndex = (int)($quiz['answer_index'] ?? 1);
    if ($answerIndex < 1 || $answerIndex > count($options)) {
        $answerIndex = 1;
    }
    $answerOption = trim((string)($options[$answerIndex - 1] ?? $options[0]));
    $explanation = trim((string)($quiz['explanation'] ?? ''));
    if ($explanation === '') {
        $explanation = 'The correct option follows JavaScript execution order and scope rules in this snippet.';
    }

    $now = time();
    return array(
        'id' => 'jsquiz_' . $topicId . '_' . $quizPostNumber . '_' . $now,
        'topic_id' => (int)$topicId,
        'quiz_post_number' => (int)$quizPostNumber,
        'topic_title' => (string)$topicTitle,
        'quiz_title' => (string)($quiz['title'] ?? ''),
        'quiz_difficulty' => js_quiz_infer_difficulty($quiz),
        'quiz_theme' => (string)($quiz['theme'] ?? ''),
        'quiz_source_name' => (string)($quiz['source_name'] ?? ''),
        'quiz_source_url' => (string)($quiz['source_url'] ?? ''),
        'quiz_snippet_size' => (string)($quiz['snippet_size'] ?? ''),
        'bot_username' => (string)($bot['username'] ?? 'BayMax'),
        'bot_name' => (string)($bot['name'] ?? 'BayMax'),
        'answer_index' => $answerIndex,
        'answer_option' => $answerOption,
        'explanation' => $explanation,
        'created_at' => $now,
        'due_at' => $now + (24 * 60 * 60),
        'answered_at' => 0,
        'answer_post_number' => 0,
    );
}

function js_quiz_post_topic($botUsername, $title, $raw)
{
    $payload = array(
        'title' => (string)$title,
        'raw' => (string)$raw,
        'category' => (int)KONVO_CATEGORY_ID,
    );

    if (!function_exists('curl_init')) {
        return array('ok' => false, 'status' => 0, 'error' => 'curl_init unavailable', 'body' => array(), 'raw' => '');
    }

    $ch = curl_init(rtrim(KONVO_BASE_URL, '/') . '/posts.json');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Api-Key: ' . KONVO_API_KEY,
            'Api-Username: ' . $botUsername,
        ),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ));

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $decoded = json_decode((string)$body, true);

    return array(
        'ok' => ($err === '') && $status >= 200 && $status < 300 && is_array($decoded),
        'status' => $status,
        'error' => $err,
        'body' => is_array($decoded) ? $decoded : array(),
        'raw' => (string)$body,
    );
}

function jsq_call_api(string $url, array $headers, ?array $payload = null): array
{
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'status' => 0, 'error' => 'curl_init unavailable', 'body' => null, 'raw' => '');
    }

    $ch = curl_init($url);
    $opts = array(
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 35,
    );
    if ($payload !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $error !== '') {
        return array('ok' => false, 'status' => 0, 'error' => $error, 'body' => null, 'raw' => '');
    }

    $decoded = json_decode((string)$body, true);
    return array(
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'error' => '',
        'body' => is_array($decoded) ? $decoded : null,
        'raw' => (string)$body,
    );
}

function jsq_poll_text_norm($text): string
{
    $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(strip_tags((string)$text));
    $text = preg_replace('/\s+/', ' ', (string)$text);
    return trim((string)$text);
}

function jsq_resolve_poll_vote_target(array $topicBody, int $topicPostId, int $answerIndex, string $answerOption): array
{
    $posts = isset($topicBody['post_stream']['posts']) && is_array($topicBody['post_stream']['posts'])
        ? $topicBody['post_stream']['posts']
        : array();
    if ($posts === array()) {
        return array('ok' => false, 'error' => 'No posts found in topic body.');
    }

    $targetPost = null;
    foreach ($posts as $p) {
        if (!is_array($p)) continue;
        if ((int)($p['id'] ?? 0) === $topicPostId) {
            $targetPost = $p;
            break;
        }
    }
    if (!is_array($targetPost) && is_array($posts[0] ?? null)) {
        $targetPost = $posts[0];
    }
    if (!is_array($targetPost)) {
        return array('ok' => false, 'error' => 'Could not locate target quiz post.');
    }

    $polls = isset($targetPost['polls']) && is_array($targetPost['polls']) ? $targetPost['polls'] : array();
    if ($polls === array() || !is_array($polls[0] ?? null)) {
        return array('ok' => false, 'error' => 'No poll found on quiz post.');
    }
    $poll = $polls[0];
    $pollName = trim((string)($poll['name'] ?? 'poll'));
    if ($pollName === '') $pollName = 'poll';

    $options = isset($poll['options']) && is_array($poll['options']) ? array_values($poll['options']) : array();
    if ($options === array()) {
        return array('ok' => false, 'error' => 'Poll options missing.');
    }

    $desiredNorm = jsq_poll_text_norm($answerOption);
    $pickedOptionId = '';
    foreach ($options as $idx => $opt) {
        if (!is_array($opt)) continue;
        $optId = trim((string)($opt['id'] ?? ''));
        $optHtml = jsq_poll_text_norm((string)($opt['html'] ?? ''));
        if ($optId === '') continue;
        if ($desiredNorm !== '' && strcasecmp($desiredNorm, $optHtml) === 0) {
            $pickedOptionId = $optId;
            break;
        }
        if ($pickedOptionId === '' && (int)$idx === max(0, $answerIndex - 1)) {
            $pickedOptionId = $optId;
        }
    }

    if ($pickedOptionId === '') {
        return array('ok' => false, 'error' => 'Could not map quiz answer to poll option id.');
    }

    return array(
        'ok' => true,
        'poll_name' => $pollName,
        'option_id' => $pickedOptionId,
        'post_id' => (int)($targetPost['id'] ?? $topicPostId),
    );
}

function jsq_vote_poll(string $botUsername, int $postId, string $pollName, string $optionId): array
{
    $headers = array(
        'Content-Type: application/json',
        'Api-Key: ' . KONVO_API_KEY,
        'Api-Username: ' . $botUsername,
    );
    $payload = array(
        'post_id' => $postId,
        'poll_name' => $pollName,
        'options' => array($optionId),
    );
    return jsq_call_api(rtrim(KONVO_BASE_URL, '/') . '/polls/vote', $headers, $payload);
}

$providedKey = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (KONVO_SECRET === '') {
    out_json(500, array('ok' => false, 'error' => 'DISCOURSE_WEBHOOK_SECRET is not configured on the server.'));
}
if ($providedKey === '' || !safe_hash_equals(KONVO_SECRET, $providedKey)) {
    out_json(403, array('ok' => false, 'error' => 'Forbidden', 'hint' => 'Pass ?key=YOUR_SECRET'));
}
if (KONVO_API_KEY === '') {
    out_json(500, array('ok' => false, 'error' => 'DISCOURSE_API_KEY is not configured on the server.'));
}

$dryRun = isset($_GET['dry_run']) && (string)$_GET['dry_run'] === '1';
$force = isset($_GET['force']) && (string)$_GET['force'] === '1';

$state = js_quiz_load_state();
$today = date('Y-m-d');

$bot = js_quiz_pick_bot($bots);
$quiz = js_quiz_enrich_quiz(js_quiz_pick_quiz($quizzes, $state));
$quizDifficulty = js_quiz_infer_difficulty($quiz);
$topicTitle = js_quiz_title((string)($quiz['title'] ?? 'Output challenge'), $quizDifficulty);
$signatureSeed = strtolower((string)($bot['username'] ?? '') . '|' . $topicTitle . '|js-quiz');
$botSignature = function_exists('konvo_signature_with_optional_emoji')
    ? konvo_signature_with_optional_emoji((string)($bot['name'] ?? 'BayMax'), $signatureSeed)
    : (function_exists('konvo_signature_base_name')
        ? konvo_signature_base_name((string)($bot['name'] ?? 'BayMax'))
        : (string)($bot['name'] ?? 'BayMax'));
$topicRaw = js_quiz_build_raw($quiz, $botSignature);
$pendingPreview = js_quiz_pending_item($quiz, $bot, 0, 1, $topicTitle);
$previewAnswerIndex = (int)($pendingPreview['answer_index'] ?? 1);
$previewAnswerOption = (string)($pendingPreview['answer_option'] ?? '');

if ($dryRun) {
    out_json(200, array(
        'ok' => true,
        'dry_run' => true,
        'action' => 'would_post_js_quiz_topic',
        'bot' => $bot,
        'topic' => array(
            'title' => $topicTitle,
            'difficulty' => $quizDifficulty,
            'difficulty_source' => (string)($quiz['difficulty'] ?? ''),
            'theme' => (string)($quiz['theme'] ?? ''),
            'snippet_size' => (string)($quiz['snippet_size'] ?? ''),
            'source_name' => (string)($quiz['source_name'] ?? ''),
            'source_url' => (string)($quiz['source_url'] ?? ''),
            'origin' => (string)($quiz['_origin'] ?? 'curated'),
            'target_difficulty' => (string)($quiz['_target_difficulty'] ?? ''),
            'target_size' => (string)($quiz['_target_size'] ?? ''),
            'category_id' => (int)KONVO_CATEGORY_ID,
            'raw_preview' => $topicRaw,
        ),
        'answer_followup_preview' => array(
            'bot_username' => (string)($pendingPreview['bot_username'] ?? ''),
            'due_at_unix' => (int)($pendingPreview['due_at'] ?? 0),
            'answer_index' => (int)($pendingPreview['answer_index'] ?? 1),
            'answer_option' => (string)($pendingPreview['answer_option'] ?? ''),
        ),
        'bot_vote_preview' => array(
            'enabled' => true,
            'strategy' => 'vote_for_most_likely_answer_option',
            'answer_index' => $previewAnswerIndex,
            'answer_option' => $previewAnswerOption,
        ),
    ));
}

$res = js_quiz_post_topic((string)$bot['username'], $topicTitle, $topicRaw);
if (!$res['ok']) {
    out_json(500, array(
        'ok' => false,
        'error' => 'Failed to post JS quiz topic.',
        'status' => $res['status'],
        'curl_error' => $res['error'],
        'response' => $res['body'],
        'raw' => $res['raw'],
    ));
}

$topicId = (int)($res['body']['topic_id'] ?? 0);
$postNumber = (int)($res['body']['post_number'] ?? 1);
$topicPostId = (int)($res['body']['id'] ?? 0);
$topicUrl = rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId . '/' . $postNumber;
$pendingItem = js_quiz_pending_item($quiz, $bot, $topicId, $postNumber, $topicTitle);
$answerIndexForVote = (int)($pendingItem['answer_index'] ?? 1);
$answerOptionForVote = (string)($pendingItem['answer_option'] ?? '');

$voteResult = array(
    'attempted' => false,
    'ok' => false,
    'status' => 0,
    'error' => '',
    'poll_name' => '',
    'option_id' => '',
    'post_id' => $topicPostId,
);

if ($topicId > 0 && $topicPostId > 0) {
    $voteResult['attempted'] = true;
    $topicHeaders = array(
        'Content-Type: application/json',
        'Api-Key: ' . KONVO_API_KEY,
        'Api-Username: ' . (string)$bot['username'],
    );
    $topicRes = jsq_call_api(rtrim(KONVO_BASE_URL, '/') . '/t/' . $topicId . '.json', $topicHeaders, null);
    if ($topicRes['ok'] && is_array($topicRes['body'])) {
        $target = jsq_resolve_poll_vote_target($topicRes['body'], $topicPostId, $answerIndexForVote, $answerOptionForVote);
        if (!empty($target['ok'])) {
            $voteResult['poll_name'] = (string)($target['poll_name'] ?? '');
            $voteResult['option_id'] = (string)($target['option_id'] ?? '');
            $voteResult['post_id'] = (int)($target['post_id'] ?? $topicPostId);
            $voteApiRes = jsq_vote_poll(
                (string)$bot['username'],
                (int)$voteResult['post_id'],
                (string)$voteResult['poll_name'],
                (string)$voteResult['option_id']
            );
            $voteResult['ok'] = (bool)($voteApiRes['ok'] ?? false);
            $voteResult['status'] = (int)($voteApiRes['status'] ?? 0);
            $voteResult['error'] = (string)($voteApiRes['error'] ?? '');
            if (!$voteResult['ok'] && $voteResult['error'] === '' && is_array($voteApiRes['body'])) {
                $voteResult['error'] = (string)($voteApiRes['body']['error'] ?? '');
            }
            if (!$voteResult['ok'] && $voteResult['error'] === '') {
                $voteResult['error'] = trim((string)($voteApiRes['raw'] ?? ''));
            }
        } else {
            $voteResult['error'] = (string)($target['error'] ?? 'Could not resolve poll vote target.');
        }
    } else {
        $voteResult['status'] = (int)($topicRes['status'] ?? 0);
        $voteResult['error'] = (string)($topicRes['error'] ?? '');
        if ($voteResult['error'] === '') {
            $voteResult['error'] = trim((string)($topicRes['raw'] ?? 'Could not fetch newly created topic for voting.'));
        }
    }
}

$recentTitles = array();
if (isset($state['recent_titles']) && is_array($state['recent_titles'])) {
    foreach ($state['recent_titles'] as $t) {
        $s = trim((string)$t);
        if ($s !== '') $recentTitles[] = $s;
    }
}
array_unshift($recentTitles, (string)($quiz['title'] ?? ''));
$recentTitles = array_values(array_unique(array_slice($recentTitles, 0, 20)));

$recentThemes = isset($state['recent_themes']) && is_array($state['recent_themes']) ? $state['recent_themes'] : array();
array_unshift($recentThemes, strtolower(trim((string)($quiz['theme'] ?? ''))));
$recentThemes = array_values(array_filter(array_unique(array_map('strval', $recentThemes))));
$recentThemes = array_slice($recentThemes, 0, 20);

$recentDifficulties = isset($state['recent_difficulties']) && is_array($state['recent_difficulties']) ? $state['recent_difficulties'] : array();
array_unshift($recentDifficulties, strtolower(trim((string)($quiz['difficulty'] ?? $quizDifficulty))));
$recentDifficulties = array_values(array_filter(array_unique(array_map('strval', $recentDifficulties))));
$recentDifficulties = array_slice($recentDifficulties, 0, 20);

$recentSizes = isset($state['recent_sizes']) && is_array($state['recent_sizes']) ? $state['recent_sizes'] : array();
array_unshift($recentSizes, strtolower(trim((string)($quiz['snippet_size'] ?? ''))));
$recentSizes = array_values(array_filter(array_unique(array_map('strval', $recentSizes))));
$recentSizes = array_slice($recentSizes, 0, 20);

$recentSources = isset($state['recent_sources']) && is_array($state['recent_sources']) ? $state['recent_sources'] : array();
array_unshift($recentSources, strtolower(trim((string)($quiz['source_name'] ?? ''))));
$recentSources = array_values(array_filter(array_unique(array_map('strval', $recentSources))));
$recentSources = array_slice($recentSources, 0, 20);

$pendingAnswers = array();
if (isset($state['pending_answers']) && is_array($state['pending_answers'])) {
    foreach ($state['pending_answers'] as $item) {
        if (!is_array($item)) continue;
        $answeredAt = (int)($item['answered_at'] ?? 0);
        $createdAt = (int)($item['created_at'] ?? 0);
        if ($answeredAt > 0 && (time() - $answeredAt) > (14 * 24 * 60 * 60)) {
            continue;
        }
        if ($answeredAt <= 0 && $createdAt > 0 && (time() - $createdAt) > (14 * 24 * 60 * 60)) {
            continue;
        }
        $pendingAnswers[] = $item;
    }
}
$pendingAnswers[] = $pendingItem;
$pendingAnswers = array_slice($pendingAnswers, -120);

$newState = is_array($state) ? $state : array();
$newState['last_post_date'] = $today;
$newState['last_topic_id'] = $topicId;
$newState['last_post_number'] = $postNumber;
$newState['last_title'] = $topicTitle;
$newState['recent_titles'] = $recentTitles;
$newState['recent_themes'] = $recentThemes;
$newState['recent_difficulties'] = $recentDifficulties;
$newState['recent_sizes'] = $recentSizes;
$newState['recent_sources'] = $recentSources;
$newState['pending_answers'] = $pendingAnswers;
js_quiz_save_state($newState);

out_json(200, array(
    'ok' => true,
    'posted' => true,
    'action' => 'posted_js_quiz_topic',
    'topic_url' => $topicUrl,
    'bot' => $bot,
    'topic' => array(
        'title' => $topicTitle,
        'difficulty' => $quizDifficulty,
        'difficulty_source' => (string)($quiz['difficulty'] ?? ''),
        'theme' => (string)($quiz['theme'] ?? ''),
        'snippet_size' => (string)($quiz['snippet_size'] ?? ''),
        'source_name' => (string)($quiz['source_name'] ?? ''),
        'source_url' => (string)($quiz['source_url'] ?? ''),
        'origin' => (string)($quiz['_origin'] ?? 'curated'),
        'target_difficulty' => (string)($quiz['_target_difficulty'] ?? ''),
        'target_size' => (string)($quiz['_target_size'] ?? ''),
        'category_id' => (int)KONVO_CATEGORY_ID,
    ),
    'answer_followup' => array(
        'bot_username' => (string)($pendingItem['bot_username'] ?? ''),
        'due_at_unix' => (int)($pendingItem['due_at'] ?? 0),
        'answer_index' => (int)($pendingItem['answer_index'] ?? 1),
        'answer_option' => (string)($pendingItem['answer_option'] ?? ''),
    ),
    'bot_vote' => $voteResult,
));
