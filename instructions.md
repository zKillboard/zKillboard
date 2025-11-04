# Route Conversion Instructions

- Can test using http://localhost/
- Can find errors at /var/log/nginx/zkill_error.log
- when converting a view file, look for any routes that use it and convert them all together for smoother testing
- ensure routes have slim3 syntax

## Core Principle
- **Routes.php**: Only handles routing - maps URLs to handler functions
- **View files**: Convert each `/view/*.php` into a `handler($request, $response, $args)` function
- **Handler function**: Does all logic and returns appropriate response (render, redirect, JSON, etc.)

## Step-by-Step Process

### 1. Identify Routes to Convert
- Search for routes still using: `ob_start()`, `$GLOBALS['capture_render_data']`, `ob_end_clean()`
- Focus on one route at a time

### 2. For Each Route
**A. Update the View File:**
- Find the existing `/view/filename.php`
- Wrap all existing logic in a `function handler($request, $response, $args, $container)` 
- Replace `$app->render('template.html', $data)` with `return $container->view->render($response, 'template.html', $data);`
- Replace `$app->redirect()` with `return $response->withHeader('Location', 'url')->withStatus(302);`
- Replace `$app->notFound()` with `return $response->withStatus(404);`
- Extract route parameters from `$args` array instead of global variables
- Handler returns the final PSR-7 response object

**B. Update the Route in routes.php:**
```php
// OLD (complex pattern):
ob_start();
$GLOBALS['capture_render_data'] = true;
include 'view/filename.php';
// ... complex logic ...

// NEW (simple pattern):
require_once 'view/filename.php';
return handler($request, $response, $args, $this);
```

### 3. Handle Special Cases
- **JSON APIs**: Return `$response->withHeader('Content-Type', 'application/json')->getBody()->write(json_encode($data)); return $response;`
- **Redirects**: Return `$response->withHeader('Location', 'url')->withStatus(302);`
- **Errors**: Return `$response->withStatus(404);` or appropriate error status
- **Templates**: Return `$container->view->render($response, 'template.html', $data);`

### 4. Test After Each Change
- Run test suite: `php tests/BlankContentTestSuite.php`
- Check specific page in browser
- Fix immediately if broken before moving to next route

### 5. Clean Up
- **DO NOT** create `/view/data/` directory
- Remove any duplicate data files if accidentally created
- Keep all logic in existing `/view/*.php` files as handler functions

## Key Success Criteria
- All existing view files converted to handler functions
- Routes only do routing - call handler functions
- 100% test pass rate maintained  
- No blank/broken pages

## Example Conversion

### Before (view/example.php):
```php
<?php
$data = ['message' => 'Hello World'];
$app->render('example.html', $data);
```

### After (view/example.php):
```php
<?php
function handler($request, $response, $args, $container) {
    $data = ['message' => 'Hello World'];
    return $container->view->render($response, 'example.html', $data);
}
```

### Before (routes.php):
```php
$app->get('/example/', function ($request, $response, $args) {
    ob_start();
    $GLOBALS['capture_render_data'] = true;
    include 'view/example.php';
    $output = ob_get_clean();
    
    if (isset($GLOBALS['render_data'])) {
        global $twig;
        $output = $twig->render($GLOBALS['render_template'], $GLOBALS['render_data']);
        unset($GLOBALS['render_data'], $GLOBALS['render_template']);
    }
    
    $response->getBody()->write($output);
    return $response;
});
```

### After (routes.php):
```php
$app->get('/example/', function ($request, $response, $args) {
    require_once 'view/example.php';  
    return handler($request, $response, $args, $this);
});
```