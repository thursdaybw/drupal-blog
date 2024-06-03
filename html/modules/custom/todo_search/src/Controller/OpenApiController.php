<?PHP

namespace Drupal\todo_search\Controller;

use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Controller\ControllerBase;

class OpenApiController extends ControllerBase {
  public function serve() {
    $module_path = \Drupal::service('extension.list.module')->getPath('todo_search');
    $file_path = $module_path . '/files/openapi.yml';
    if (file_exists($file_path)) {
      $content = file_get_contents($file_path);
      return new Response($content, 200, ['Content-Type' => 'text/yaml']);
    }
    else {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }
  }
}
