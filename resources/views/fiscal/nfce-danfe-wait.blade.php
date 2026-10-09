<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>Preparando DANFE</title>
  <style>
    html,body{height:100%;margin:0}
    body{display:grid;place-items:center;font:14px Arial,sans-serif;background:#f6f8fa;color:#36495a}
    .state{text-align:center;padding:20px;max-width:360px}
    [hidden]{display:none!important}
    .spinner{width:26px;height:26px;border:3px solid #dce7f0;border-top-color:#2777b8;border-radius:50%;margin:0 auto 18px;animation:rotate .8s linear infinite}
    @keyframes rotate{to{transform:rotate(360deg)}}
    a{display:inline-block;margin-top:14px;color:#286ba7;text-decoration:none;border:1px solid #c5d4e1;padding:9px 16px;border-radius:4px;background:white}
    .state p{margin:8px 0;line-height:1.6}
  </style>
</head>
<body>
  <div class="state" aria-live="polite">
    <div class="spinner" id="spinner" @if($failed) hidden @endif></div>
    <p id="message">{{ $failed ? 'Não foi possível preparar o DANFE.' : 'Preparando DANFE...' }}</p>
    <a id="retry" href="{{ $url }}?retry=1" @unless($failed) hidden @endunless>Tentar novamente</a>
  </div>
  @unless($failed)
  <script>
    (function(){
      const url=@json($url);
      let attempts=0;
      let inflight=false;
      async function check(){
        if(inflight) return;
        inflight=true;
        try {
          const response=await fetch(url+'?status=1',{
            credentials:'same-origin',
            cache:'no-store',
            headers:{'Accept':'application/json'}
          });
          if(!response.ok) throw new Error('Falha na consulta');
          const data=await response.json();
          if(data.status==='ready'){location.replace(url);return;}
          if(data.status==='failed') {fail();return;}
        }catch(error){}
        finally{inflight=false;}
        if(++attempts<24) setTimeout(check,650);
        else fail();
      }
      function fail(){
        document.getElementById('spinner').hidden=true;
        document.getElementById('message').textContent='Não foi possível preparar o DANFE.';
        document.getElementById('retry').hidden=false;
      }
      setTimeout(check,500);
    })();
  </script>
  @endunless
</body>
</html>
