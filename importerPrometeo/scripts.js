

function mostrarVentana(ventana) { 
    console.log("Muestra ventana");
    var v = document.getElementById(ventana);
    v.style.marginTop = "100px";
    v.style.left = ((document.body.clientWidth-350) / 2) +  "px";
    v.style.display = 'block';
} 

function ocultarVentana(id) {
    var ventana = document.getElementById(id);
    ventana.style.display = 'none';
}

function clickIntegrar() {
    mostrarVentana('ventanaCargando');
    jQuery.post( integracionModalScript.urlBase+"/wp-admin/admin-ajax.php", {
        action: 'showModalIntegrador'
     }, 'json').always(function(data) {
        ocultarVentana('ventanaCargando');
        if(data==10){
              mostrarVentana('miVentanaOK');
        }else{
            mostrarVentana('miVentanaERROR');
        }
      });
}