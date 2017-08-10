    <?php
/**
 * Plugin Name: Importar Eventos y Noticias a Prometeo
 * Plugin URI: 
 * Description: El plugin importa los eventos y las noticias al Wordpress de Prometeo
 * Version: 1.0.0
 * Author: SmartWay Studio
 *
 * Text Domain: importerPrometeo
 */

register_activation_hook( __FILE__, 'funcion_activacion_importerPrometeo' ); //Función a ejecutar cuando se activa el plugin
register_deactivation_hook( __FILE__, 'funcion_desactivacion_importerPrometeo' ); //Función a ejecutar cuando se desactiva el plugin

add_action( 'wp_insert_post', 'publish_post_importerPrometeo', 10, 1 );
add_action('admin_menu', 'importer_prometeo_plugin_menu');

function importer_prometeo_plugin_menu() {
    add_menu_page('Integracion_Prometeo',       //Título de la página
        'Integracion Prometeo',                   //Título del menú
        'administrator',                     //Rol que puede acceder
        'integracion_prometeo_settings',        //Id de la página de opciones
        'integracion_prometeo_page_setting',    //Función que pinta la página de configuración del plugin
        'dashicons-upload');             //Icono del menú
}

function funcion_activacion_importerPrometeo() {
  global $wpdb;
  $create_table = "
    CREATE TABLE IF NOT EXISTS {$wpdb->prefix}sw_posts_sincronizados (
      `post_id` VARCHAR(255) NOT NULL,
      `tipo` VARCHAR(255) NOT NULL,
      `fecha` DATE NOT NULL,
      PRIMARY KEY (post_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

    dbDelta( $create_table );

}

function funcion_desactivacion_importerPrometeo() {

}

function publish_post_importerPrometeo($post_id){
  global $wpdb;
  $importer = new ImporterPrometeoIntegradores;

  $post = get_post($post_id);
  if($post->post_status == "publish"){
    $postSincronizado = $wpdb->get_row('SELECT * FROM '. $wpdb->prefix . 'sw_posts_sincronizados WHERE post_id='.$post->ID);
    if(empty($postSincronizado)){
      if($post->post_type == "ajde_events"){
        $importer ->integrarEvento($post);
      }else{
        $idCategoryNotices = $importer->getIDCategoryNotices();
        $importer->integrarNoticia($post,$idCategoryNotices);
      }
    }
  }
}


if(!class_exists('ImporterPrometeoIntegradores')) {
  class ImporterPrometeoIntegradores {
    public $user = 'INTRODUCEUSER';
    public $password = 'INTRODUCEPASSWORD';
    public $urlBaseApi = 'http://prometeoemprende.es';

    function addNotice($title, $content,$category) {
      $data = array(
        'title' => $title,
        'content' => $content,
        'status' => 'draft'
      );

      if($category!=-1){
        $data['categories'] = array($category);
      }

      $args = array(
        'headers' => array(
          'Authorization' => 'Basic ' . base64_encode( $this->user . ':' . $this->password ),
        ),
        'timeout' => 50,
        'method'  => 'POST',
        'body'    =>  $data,
      );

      $url = $this->urlBaseApi.'/wp-json/wp/v2/posts';
      $response = wp_remote_post( $url, $args );

      if(!is_wp_error( $response ) && $response['response']['code']=="201" && !empty($response['body'])){
        $post = json_decode($response['body'], TRUE);
        return $post;
      }
      return null;
    }

    function addEvent($id,$title,$content,$startTime,$endTime,$allDay,$direccion,$longitud,$latitud,$urlInscripcion){
      $data = array(
        'id' => $id,
        'title' => $title,
        'content' => $content,
        'startTime' => $startTime,
        'endTime' => $endTime,
        'allDay' => $allDay,
        'direccion' => $direccion,
        'longitud' => $longitud,
        'latitud' => $latitud,
        'urlInscripcion' => $urlInscripcion
      );

      $args = array(
        'headers' => array(
          'Authorization' => 'Basic ' . base64_encode( $this->user . ':' . $this->password ),
        ),
        'timeout' => 50,
        'method'  => 'POST',
        'body'    =>  $data,
      );

      $url = $this->urlBaseApi.'/wp-json/addEP/v1/event';
      $response = wp_remote_post( $url, $args );
      if(!is_wp_error( $response ) && !empty($response['body'])){
        return $response['body'];
      }
      return "ERROR";
    }

    function importacionInicial(){
      set_time_limit(0);
      global $wpdb;
      $posts = get_posts();
      $idCategoryNotices = $this->getIDCategoryNotices();
      foreach ($posts as $post) {
        $postSincronizado = $wpdb->get_row('SELECT * FROM '. $wpdb->prefix . 'sw_posts_sincronizados WHERE post_id='.$post->ID);
        if(empty($postSincronizado) && $post->post_status=="publish"){
          $this->integrarNoticia($post,$idCategoryNotices);
        }
      }

      $events = new WP_Query(array(
        'posts_per_page'=>-1,
        'post_type' => 'ajde_events',
        'post_status'=>'any'      
      ));
      foreach ($events->posts as $post) {
        $postSincronizado = $wpdb->get_row('SELECT * FROM '. $wpdb->prefix . 'sw_posts_sincronizados WHERE post_id='.$post->ID);
        if(empty($postSincronizado) && $post->post_status=="publish"){
          $this->integrarEvento($post);
        }
      }
    }

    function integrarNoticia($post, $idCategoryNotices){
      global $wpdb;
      $categories = wp_get_post_categories($post->ID, array('fields' => 'all'));
      $noticia = true;
      foreach ($categories as $category) {
        if($category->slug=="blog"){
          $noticia = false; 
          break; 
        }
      }
      if($noticia){
        $result = $this->addNotice($post->post_title, $post->post_content, $idCategoryNotices);
      }else{
        $result = $this->addNotice($post->post_title, $post->post_content, -1);
      }
      if($result!=null){
        $wpdb->insert( $wpdb->prefix . 'sw_posts_sincronizados', array( 'post_id' => $post->ID, 'tipo' => $post->post_type, 'fecha' => $post->post_date));
      }
    }

    function integrarEvento($post) {
      global $wpdb;

      $taxopt = get_option( "evo_tax_meta");
      $Locterms = wp_get_object_terms($post->ID, 'event_location');
      $location_address = '';
      $latitude = '';
      $longitude = '';

      $terms_taxonomies = $wpdb->get_results('SELECT * FROM '. $wpdb->prefix . 'term_taxonomy where taxonomy="event_location"');
      $terms_relationships = $wpdb->get_results('SELECT * FROM '. $wpdb->prefix . 'term_relationships where object_id="'.$post->ID.'"');
      $intersect = array_intersect(array_column(array_map(function($o){return (array)$o;},$terms_relationships),'term_taxonomy_id'), array_column(array_map(function($o){return (array)$o;},$terms_taxonomies), 'term_taxonomy_id'));

      if(!empty($intersect)){
        $termMeta = evo_get_term_meta('event_location',$intersect[0], $taxopt, true);
        $location_address = $termMeta['location_address'];
        $latitude = $termMeta['location_lat'];
        $longitude = $termMeta['location_lon'];
      }

      $postMeta = get_post_meta($post->ID);
      $allDay = isset($postMeta['evcal_allday']) ? $postMeta['evcal_allday'][0] : 0;
      $urlInscripcion =  isset($postMeta['evcal_lmlink']) ? $postMeta['evcal_lmlink'][0] : '';
      $startDate = isset($postMeta['evcal_srow']) ? $postMeta['evcal_srow'][0] : null;
      $endDate = isset($postMeta['evcal_erow']) ? $postMeta['evcal_erow'][0] : null;
      $result = $this->addEvent($post->ID,$post->post_title,$post->post_content,$startDate,$endDate,$allDay,$location_address,$longitude,$latitude,$urlInscripcion);
      if(strpos($result, 'OK') !== false){
        $wpdb->insert( $wpdb->prefix . 'sw_posts_sincronizados', array( 'post_id' => $post->ID, 'tipo' => $post->post_type, 'fecha' => $post->post_date));
      }
    }

    function getIDCategoryNotices_old(){

      $args = array(
        'headers' => array(
          'Authorization' => 'Basic ' . base64_encode( $this->user . ':' . $this->password ),
        ),
        'timeout' => 50,
        'method'  => 'GET',
        'body'    =>  array(
            'slug' => 'noticias'
        ),
      );

      $url = $this->urlBaseApi.'/wp-json/wp/v2/categories';
      $response = wp_remote_post( $url, $args );
      if(!is_wp_error( $response ) && $response!=null && isset($response['body'])){
        $categories = json_decode($response['body'], TRUE);

      if($categories != "" && count($categories)>0){
        return $categories[0]['id'];
      }else return -1;
      }else return -1;
      
    }

    function getIDCategoryNotices(){

      $args = array(
        'headers' => array(
          'Authorization' => 'Basic ' . base64_encode( $this->user . ':' . $this->password ),
        ),
        'timeout' => 50,
        'method'  => 'GET',
        'body'    =>  array(
            'slug' => 'noticias'
        ),
      );

      $url = $this->urlBaseApi.'/wp-json/addEP/v1/category';
      $response = wp_remote_post( $url, $args );
      if(!is_wp_error( $response ) && $response!=null && isset($response['body'])){
        $category = json_decode($response['body'], TRUE);
        if($category != "") {
          return $category;
        }
      }

      return -1;
    }

  }
}



/*Función para construir la página de configuración del plugin*/
function integracion_prometeo_page_setting() {
  ?>

  <div style="padding: 50px;">
    <h3>INTEGRACIÓN DE LOS EVENTOS, NOTICIAS Y ENTRADAS <br/> CON LA PLATAFORMA DE PROMETEO</h3>
    <form  method='POST'>
      <input type="submit" class="button action" style="background-image: linear-gradient(#5aa1d8, #2489d6);color: white;" name="integPrometeo" value="INTEGRAR">
    </form>
  </div>

  <?php
}

if (isset($_POST['integPrometeo']) && $_SERVER['REQUEST_METHOD']=="POST"){
  $importer = new ImporterPrometeoIntegradores;
  $importer->importacionInicial();
}

?>