/* Keep native errors available to diagnostics without displaying their internals. */
(()=>{
  'use strict';
  const defaultMessage='Não foi possível concluir a operação. Tente novamente.';
  const technical=/(?:SQLSTATE|PDOException|QueryException|JsonException|Illuminate\\|Symfony\\|Stack trace|Traceback|Call to undefined|Undefined (?:array key|variable|index)|syntax error|fatal error|Connection (?:refused|reset)|cURL error\s*\d+|getaddrinfo|failed to open stream|TypeError|ReferenceError|SyntaxError|Failed to fetch|NetworkError|Load failed|Unexpected (?:token|end)|Cannot read properties|is not a function|Maximum call stack|[A-Z]:\\|\/(?:var|srv|workspace|home|app|opt|tmp)\/|^\s*(?:<!doctype|<html|\{\s*"|\[\s*\{))/i;
  const translations={
    'Unauthenticated.':'Entre novamente para continuar.',
    'This action is unauthorized.':'Você não possui permissão para esta operação.',
    'Forbidden':'Você não possui permissão para esta operação.',
    'Not Found':'O registro ou a página não foi encontrado.',
    'Too Many Requests':'Muitas tentativas em pouco tempo. Aguarde antes de tentar novamente.',
    'The given data was invalid.':'Confira os dados informados.'
  };
  window.LumeronUserMessage=Object.freeze({
    fromError(error,fallback=defaultMessage){
      const safeFallback=typeof fallback==='string' && fallback.trim() && !technical.test(fallback)
        ? fallback : defaultMessage;
      const message=typeof error==='string' ? error.trim()
        : typeof error?.message==='string' ? error.message.trim() : '';
      const nativeFailure=error && typeof error==='object'
        && ['SyntaxError','TypeError','ReferenceError','RangeError','EvalError','URIError'].includes(error.name);
      if(nativeFailure || !message || technical.test(message) || ['Server Error','Internal Server Error','Service Unavailable'].includes(message))return safeFallback;
      return translations[message] || message;
    }
  });
})();
