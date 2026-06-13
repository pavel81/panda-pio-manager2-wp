(function($){
  $(document).on('click', '#panda-pio-test', function(e){
    e.preventDefault();
    var $box = $('#panda-pio-result').removeClass('panda-ok panda-err').show().text('Testuji…');

    $.post(PandaPIO.ajaxUrl, {
      action: 'panda_pio_test_conn',
      nonce: PandaPIO.nonce
    }).done(function(resp){
      if(resp && resp.success){
        var msg = (resp.data && resp.data.message) ? resp.data.message : 'Připojeno';
        var code = (resp.data && resp.data.code) ? ' (HTTP ' + resp.data.code + ')' : '';
        $box.addClass('panda-ok').html('<strong>OK:</strong> ' + msg + code);
      } else {
        var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Neznámá chyba';
        var http = (resp && resp.data && resp.data.code) ? ' (HTTP ' + resp.data.code + ')' : '';
        $box.addClass('panda-err').html('<strong>Chyba:</strong> ' + msg + http);
      }
    }).fail(function(){
      $box.addClass('panda-err').text('Chyba požadavku (AJAX/XHR).');
    });
  });
})(jQuery);