<?php
    /**
     * @license
     *
     * PHP Error
     *
     * Copyright (c) 2012 Joseph Lenton
     * All rights reserved.
     *
     * Redistribution and use in source and binary forms, with or without
     * modification, are permitted provided that the following conditions are met:
     *     * Redistributions of source code must retain the above copyright
     *       notice, this list of conditions and the following disclaimer.
     *     * Redistributions in binary form must reproduce the above copyright
     *       notice, this list of conditions and the following disclaimer in the
     *       documentation and/or other materials provided with the distribution.
     *     * Neither the name of the <organization> nor the
     *       names of its contributors may be used to endorse or promote products
     *       derived from this software without specific prior written permission.
     *
     * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
     * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
     * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
     * DISCLAIMED. IN NO EVENT SHALL <COPYRIGHT HOLDER> BE LIABLE FOR ANY
     * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
     * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
     * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
     * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
     * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
     * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
     *
     * Uses:
     *      jQuery      http://jquery.com/
     */

    /**
     * PHP Error
     *
     * --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- ---
     *
     * WARNING! It is downright _DANGEROUS_ to use this in production, on
     * a live website. It should *ONLY* be used for development.
     *
     * PHP Error will kill your environment at will, clear the output
     * buffers, and allows HTML injection from exceptions.
     *
     * In future versions it plans to do far more then that.
     *
     * If you use it in development, awesome! If you use it in production,
     * you're an idiot.
     *
     * --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- ---
     *
     * = Info
     *
     * A small API for replacing the standard PHP errors, with prettier
     * error reporting. This will change the error reporting level, and this
     * is deliberate, as I believe in strict development errors.
     *
     * simple usage:
     *
     *      \php_error\reportErrors();
     *
     * Advanced example:
     *
     * There is more too it if you want more customized error handling. You
     * can pass in options, to customize the setup, and you get back a
     * handler you can alter at runtime.
     *
     *      $handler = new \php_error\ErrorHandler( $myOptions );
     *      $handler->turnOn();
     *
     * There should only ever be one handler! This is an (underdstandable)
     * limitation in PHP. It's because if an exception or error is raised,
     * then there is a single point of handling it.
     *
     * = INI Options
     *
     * - php_error.force_disabled When set to a true value (such as on),
     *                            this forces this to be off.
     *                            This is so you can disable this script
     *                            in your production servers ini file,
     *                            incase you accidentally upload this there.
     *
     * --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- ---
     *
     * @author Joseph Lenton | https://github.com/josephlenton
     */

    namespace php_error;

    use \php_error\FileLinesSet,
        \php_error\ErrorHandler;

    use \Closure,
        \Exception,
        \ErrorException,
        \InvalidArgumentException;

    use \ReflectionMethod,
        \ReflectionFunction,
        \ReflectionParameter;

    global $_php_error_already_setup,
           $_php_error_global_handler,
           $_php_error_is_ini_enabled;

    /*
     * Avoid being run twice.
     */
    if ( empty($_php_error_already_setup) ) {
        $_php_error_already_setup = true;

        /*
         * These are used as token identifiers by PHP.
         *
         * If they are missing, then they should never pop out of PHP,
         * so we just give them their future value.
         *
         * They are primarily here so I don't have to alter the 5.3
         * compliant code. Instead I can delete pre-5.3 code (this
         * code), in the future.
         *
         * As long as the value is unique, and does not clash with PHP,
         * then any number could be used. That is why they start counting
         * at 100,000.
         */

        $missingIdentifier = array(
                'T_INSTEADOF',
                'T_TRAIT',
                'T_TRAIT_C',
                'T_YIELD',
                'T_FINALLY'
        );

        $counter = 100001;
        foreach ( $missingIdentifier as $id ) {
            if ( ! defined($id) ) {
                define( $id, $counter++ );
            }
        }

        /*
         * Check if it's empty, in case this file is loaded multiple times.
         */
        if ( ! isset($_php_error_global_handler) ) {
            $_php_error_global_handler = null;

            $_php_error_is_ini_enabled = false;

            /*
             * check both 'disable' and 'disabled' incase it's mispelt
             * check that display errors is on
             * and ensure we are *not* a command line script.
             */
            $_php_error_is_ini_enabled = e !== 'cli';
        }

        /**
         * This is shorthand for turning off error handling,
         * calling a block of code, and then turning it on.
         *
         * However if 'reportErrors' has not been called,
         * then this will silently do nothing.
         *
         * @param callback A PHP function to call.
         * @return The result of calling the callback.
         */
        function withoutErrors( $callback ) {
            global $_php_error_global_handler;

            if ( $_php_error_global_handler !== null ) {
                return $_php_error_global_handler->withoutErrors( $callback );
            } else {
                return $callback();
            }
        }

        /**
         * Turns on error reporting, and returns the handler.
         *
         * If you just want error reporting on, then don't bother
         * catching the handler. If you're building something
         * clever, like a framework, then you might want to grab
         * and use it.
         *
         * Note that calling this a second time will replace the
         * global error handling with a new error handler.
         * The existing one will be turned off, and the new one
         * turned on.
         *
         * You can't use two at once!
         *
         * @param options Optional, options declaring how PHP Error should be setup and used.
         * @return The ErrorHandler used for reporting errors.
         */
        function reportErrors( $options=null ) {
            $handler = new ErrorHandler( $options );
            return $handler->turnOn();
        }

        /**
         * The actual handler. There can only ever be one.
         */
        class ErrorHandler
        {
            const REGEX_DOCTYPE = '/<( )*!( *)DOCTYPE([^>]+)>/';

            const REGEX_PHP_IDENTIFIER = '\b[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*';
            const REGEX_PHP_CONST_IDENTIFIER = '/\b[A-Z_\x7f-\xff][A-Z0-9_\x7f-\xff]*/';

            /**
             * Matches:
             *  {closure}()
             *  blah::foo()
             *  foo()
             *
             * It is:
             *      a closure
             *      or a method or function
             *      followed by parenthesis '()'
             *
             *      a function is 'namespace function'
             *      a method is 'namespace class::function', or 'namespace class->function'
             *      the whole namespace is optional
             *          namespace is made up of an '\' and then repeating 'namespace\'
             *          both the first slash, and the repeating 'namespace\', are optional
             *
             * 'END' matches it at the end of a string, the other one does not.
             */
            const REGEX_METHOD_OR_FUNCTION_END = '/(\\{closure\\})|(((\\\\)?(\b[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*\\\\)*)?\b[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(::[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)?)\\(\\)$/';
            const REGEX_METHOD_OR_FUNCTION     = '/(\\{closure\\})|(((\\\\)?(\b[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*\\\\)*)?\b[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(::[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)?)\\(\\)/';

            const REGEX_VARIABLE = '/\b[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/';

            const REGEX_MISSING_SEMI_COLON_FOLLOWING_LINE = '/^ *(return|}|if|while|foreach|for|switch)/';

            /**
             * The number of lines to take from the file,
             * where the error is reported. This is the number
             * of lines around the line in question,
             * including that line.
             *
             * So '9' will be the error line + 4 lines above + 4 lines below.
             */
            const NUM_FILE_LINES = 13;

            const FILE_TYPE_APPLICATION = 1;
            const FILE_TYPE_IGNORE      = 2;
            const FILE_TYPE_ROOT        = 3;

            /*
             * These are the various magic identifiers,
             * used for headers, post requests, and so on.
             * 
             * Their main purpose is to be long and more or less unique,
             * enough that a collision with user code is rare.
             */

            const PHP_ERROR_MAGIC_HEADER_KEY = 'PHP_ERROR_MAGIC_HEADER';
            const PHP_ERROR_MAGIC_HEADER_VALUE = 'php_stack_error';
            const MAGIC_IS_PRETTY_ERRORS_MARKER = '<!-- __magic_php_error_is_a_stack_trace_constant__ -->';

            const HEADER_SAVE_FILE        = 'PHP_ERROR_SAVE_FILES';

            const POST_FILE_LOCATION      = 'php_error_upload_file';

            const PHP_ERROR_INI_PREFIX = 'php_error';

            /**
             * At the time of writing, scalar type hints are unsupported.
             * By scalar, I mean 'string' and 'integer'.
             *
             * If they do get added, this is here as a trap to turn scalar
             * type hint warnings on and off.
             */
            private static $IS_SCALAR_TYPE_HINTING_SUPPORTED = false;

            private static $SCALAR_TYPES = array(
                    'string', 'integer', 'float', 'boolean',
                    'bool', 'int', 'number'
            );

            /**
             * A mapping of PHP internal symbols,
             * mapped to descriptions of them.
             */
                private static $PHP_SYMBOL_MAPPINGS = array(
                        '$end'                      => 'end of file',
                        'T_ABSTRACT'                    => 'abstract',
                        'T_AND_EQUAL'                   => "'&='",
                        'T_ARRAY'                   => 'array',
                        'T_ARRAY_CAST'                  => 'array cast',
                        'T_AS'                      => "'as'",
                        'T_BOOLEAN_AND'             => "'&&'",
                        'T_BOOLEAN_OR'              => "'||'",
                        'T_BOOL_CAST'               => 'boolean cast',
                        'T_BREAK'                   => 'break',
                        'T_CASE'                    => 'case',
                        'T_CATCH'                   => 'catch',
                        'T_CLASS'                   => 'class',
                        'T_CLASS_C'                 => '__CLASS__',
                        'T_CLONE'                   => 'clone',
                        'T_CLOSE_TAG'               => 'closing PHP tag',
                        'T_CONCAT_EQUAL'                => "'.='",
                    'T_CONST'                       => 'const',
                    'T_CONSTANT_ENCAPSED_STRING'    => 'string',
                    'T_CONTINUE'                    => 'continue',
                    'T_CURLY_OPEN'                  => '\'{$\'',
                    'T_DEC'                         => '-- (decrement)',
                    'T_DECLARE'                     => 'declare',
                    'T_DEFAULT'                     => 'default',
                    'T_DIR'                         => '__DIR__',
                    'T_DIV_EQUAL'                   => "'/='",
                    'T_DNUMBER'                     => 'number',
                    'T_DOLLAR_OPEN_CURLY_BRACES'    => '\'${\'',
                    'T_DO'                          => "'do'",
                    'T_DOUBLE_ARROW'                => "'=>'",
                    'T_DOUBLE_CAST'                 => 'double cast',
                    'T_DOUBLE_COLON'                => "'::'",
                    'T_ECHO'                        => 'echo',
                    'T_ELSE'                        => 'else',
                    'T_ELSEIF'                      => 'elseif',
                    'T_EMPTY'                       => 'empty',
                    'T_ENCAPSED_AND_WHITESPACE'     => 'non-terminated string',
                        'T_ENDDECLARE'                  => 'enddeclare',
                        'T_ENDFOR'                      => 'endfor',
                        'T_ENDFOREACH'                  => 'endforeach',
                        'T_ENDIF'                       => 'endif',
                    'T_ENDSWITCH'                   => 'endswitch',
                    'T_ENDWHILE'                    => 'endwhile',
                    'T_EVAL'                        => 'eval',
                    'T_EXIT'                        => 'exit call',
                    'T_EXTENDS'                     => 'extends',
                    'T_FILE'                        => '__FILE__',
                    'T_FINAL'                       => 'final',
                    'T_FINALLY'                     => 'finally',
                    'T_FOR'                         => 'for',
                    'T_FOREACH'                     => 'foreach',
                    'T_FUNCTION'                    => 'function',
                    'T_FUNC_C'                      => '__FUNCTION__',
                    'T_GLOBAL'                      => 'global',
                    'T_GOTO'                        => 'goto',
                    'T_HALT_COMPILER'               => '__halt_compiler',
                        'T_IF'                          => 'if',
                    'T_IMPLEMENTS'                  => 'implements',
                        'T_INC'                         => '++ (increment)',
                        'T_INCLUDE'                    => 'include',
                        'T_INCLUDE_ONCE'                =>'include_once',
                        'T_INSTANCEOF'              => 'instanceof',
                        'T_INSTEADOF'               => 'insteadof',
                        'T_INT_CAST'                => 'int cast',
                        'T_INTERFACE'               => 'interface',
                        'T_ISSET'                   => 'isset',
                        'T_IS_EQUAL'                => "'=='",
                        'T_IS_GREATER_OR_EQUAL'         => "'>='",
                        'T_IS_IDENTICAL'                => "'==='",
                    'T_IS_NOT_EQUAL'                => "'!=' or '<>'",
                    'T_IS_NOT_IDENTICAL'            => "'!=='",
                    'T_IS_SMALLER_OR_EQUAL'         => "'<='",
                    'T_LINE'                        => '__LINE__',
                     'T_LIST'                        => 'list',
                        'T_LNUMBER'                     => 'number',
                        'T_LOGICAL_AND'                 => "'and'",
                        'T_LOGICAL_OR'                  => "'or'",
                        'T_LOGICAL_XOR'                 => "'xor'",
                        'T_METHOD_C'                    => '__METHOD__',
                        'T_MINUS_EQUAL'                 => "'-='",
                        'T_MOD_EQUAL'                   => "'%='",
                        'T_MUL_EQUAL'                   => "'*='",
                        'T_NAMESPACE'                   => 'namespace',
                        'T_NEW'                     => 'new',
                        'T_NUM_STRING'              => 'array index in a string',
                     'T_NS_C'                        => '__NAMESPACE__',
                    'T_NS_SEPARATOR'                => 'namespace seperator',
                        'T_OBJECT_CAST'                 => 'objectcast',
                        'T_OBJECT_OPERATOR'             => "'->'",
                        'T_OLD_FUNCTION'                => 'old_function',
                        'T_OPEN_TAG'                    => "'<?php' or '<?'",
                        'T_OPEN_TAG_WITH_ECHO'      => "'<?php echo '",
                        'T_OR_EQUAL'                => "'|='",
                        'T_PAAMAYIM_NEKUDOTAYIM'        => "'::'",
                        'T_PLUS_EQUAL'              => "'+='",
                        'T_PRINT'                   => 'print',
                        'T_PRIVATE'                 => 'private',
                        'T_PUBLIC'                  => 'public',
                        'T_PROTECTED'               => 'protected',
                        'T_REQUIRE'                 => 'require',
                        'T_REQUIRE_ONCE'            => 'require_once',
                        'T_RETURN'                  => 'return',
                        'T_SL'                      => "'<<'",
                        'T_SL_EQUAL'                => "'<<='",
                        'T_SR'                      => "'>>'",
                        'T_SR_EQUAL'                => "'>>='",
                        'T_START_HEREDOC'           => "'<<<'",
                        'T_STATIC'                      => 'static',
                        'T_STRING'                      => 'string',
                        'T_STRING_CAST'                 => 'string cast',
                    'T_SWITCH'                      => 'switch',
                'T_THROW'                       => 'throw',
                    'T_TRY'                         => 'try',
                        'T_TRAIT'                       => 'trait',
                        'T_TRAIT_C'                     => '__trait__',
                        'T_UNSET'                   => 'unset',
                        'T_UNSET_CAST'              => 'unset cast',
                        'T_USE'                 => 'use',
                        'T_VAR'                         => 'var',
                        'T_VARIABLE'                    => 'variable',
                    'T_WHILE'                       => 'while',
                    'T_WHITESPACE'                  => 'whitespace',
                    'T_XOR_EQUAL'                   => "'^='",
                    'T_YIELD'                       => 'yield'
                );
    
            private static $syntaxMap = array(
                        'const'                   => 'syntax-literal',
                        'reference_ampersand'         => 'syntax-function',
    
                        T_COMMENT                     => 'syntax-comment',
                        T_DOC_COMMENT                 => 'syntax-comment',
    
                      T_ABSTRACT                    => 'syntax-keyword',
                      T_AS                          => 'syntax-keyword',
                      T_BREAK                       => 'syntax-keyword',
                      T_CASE                        => 'syntax-keyword',
                        T_CATCH                       => 'syntax-keyword',
                        T_CLASS                       => 'syntax-keyword',
    
                      T_CONST                       => 'syntax-keyword',
    
                    T_CONTINUE                    => 'syntax-keyword',
                    T_DECLARE                     => 'syntax-keyword',
                    T_DEFAULT                     => 'syntax-keyword',
                  T_DO                          => 'syntax-keyword',
    
                    T_ELSE                        => 'syntax-keyword',
                    T_ELSEIF                      => 'syntax-keyword',
                    T_ENDDECLARE                  => 'syntax-keyword',
                    T_ENDFOR                      => 'syntax-keyword',
                  T_ENDFOREACH                  => 'syntax-keyword',
                      T_ENDIF                       => 'syntax-keyword',
                      T_ENDSWITCH                   => 'syntax-keyword',
                      T_ENDWHILE                    => 'syntax-keyword',
                      T_EXTENDS                     => 'syntax-keyword',
    
                    T_FINAL                       => 'syntax-keyword',
                    T_FINALLY                     => 'syntax-keyword',
                    T_FOR                         => 'syntax-keyword',
                    T_FOREACH                     => 'syntax-keyword',
                    T_FUNCTION                    => 'syntax-keyword',
                    T_GLOBAL                      => 'syntax-keyword',
                    T_GOTO                        => 'syntax-keyword',
    
                    T_IF                          => 'syntax-keyword',
                    T_IMPLEMENTS                  => 'syntax-keyword',
                    T_INSTANCEOF                  => 'syntax-keyword',
                    T_INSTEADOF                   => 'syntax-keyword',
                    T_INTERFACE                   => 'syntax-keyword',
    
                    T_LOGICAL_AND                 => 'syntax-keyword',
                T_LOGICAL_OR                  => 'syntax-keyword',
                        T_LOGICAL_XOR                 => 'syntax-keyword',
                        T_NAMESPACE                   => 'syntax-keyword',
                        T_NEW                         => 'syntax-keyword',
                        T_PRIVATE                     => 'syntax-keyword',
                        T_PUBLIC                      => 'syntax-keyword',
                        T_PROTECTED                   => 'syntax-keyword',
                        T_RETURN                      => 'syntax-keyword',
                        T_STATIC                      => 'syntax-keyword',
                        T_SWITCH                      => 'syntax-keyword',
                        T_THROW                       => 'syntax-keyword',
                    T_TRAIT                       => 'syntax-keyword',
                    T_TRY                         => 'syntax-keyword',
                T_USE                         => 'syntax-keyword',
                    T_VAR                         => 'syntax-keyword',
                        T_WHILE                       => 'syntax-keyword',
                    T_YIELD                       => 'syntax-keyword',

                        // __VAR__ type magic constants
                    T_CLASS_C                     => 'syntax-literal',
                    T_DIR                         => 'syntax-literal',
                    T_FILE                        => 'syntax-literal',
                    T_FUNC_C                      => 'syntax-literal',
                    T_LINE                        => 'syntax-literal',
                    T_METHOD_C                    => 'syntax-literal',
                    T_NS_C                        => 'syntax-literal',
                    T_TRAIT_C                     => 'syntax-literal',

                    T_DNUMBER                     => 'syntax-literal',
                    T_LNUMBER                     => 'syntax-literal',

                    T_CONSTANT_ENCAPSED_STRING    => 'syntax-string',
                    T_VARIABLE                    => 'syntax-variable',

                    // this is for unescaped strings, which appear differently
                    // this includes function names
                    T_STRING                      => 'syntax-function',

                    // in build keywords, which work like functions
                    T_ARRAY                       => 'syntax-function',
                    T_CLONE                       => 'syntax-function',
                    T_ECHO                        => 'syntax-function',
                    T_EMPTY                       => 'syntax-function',
                    T_EVAL                        => 'syntax-function',
                    T_EXIT                        => 'syntax-function',
                    T_HALT_COMPILER               => 'syntax-function',
                        T_INCLUDE                     => 'syntax-function',
                   T_INCLUDE_ONCE                => 'syntax-function',
                    T_ISSET                       => 'syntax-function',
                      T_LIST                        => 'syntax-function',
                        T_REQUIRE_ONCE                => 'syntax-function',
                T_PRINT                   => 'syntax-function',
                        T_REQUIRE                     => 'syntax-function',
                      T_UNSET                       => 'syntax-function'
            );
    
                /**
                 * A mapping of PHP errors,
                 * mapped to descriptions of them.
                */
               private static $PHP_ERROR_MAPPINGS = array(
                	E_ERROR 				=> 'E_ERROR',
    					E_WARNING 				=> 'E_WARNING',
    					E_PARSE 				=> 'E_PARSE',
    					E_NOTICE 				=> 'E_NOTICE',
    					E_CORE_ERROR 			=> 'E_CORE_ERROR',
    				E_CORE_WARNING 			=> 'E_CORE_WARNING',
    					E_COMPILE_ERROR 		=> 'E_COMPILE_ERROR',
    					E_COMPILE_WARNING 		=> 'E_COMPILE_WARNING',
    					E_USER_ERROR			=> 'E_USER_ERROR',
    					E_USER_WARNING 			=> 'E_USER_WARNING',
    					E_USER_NOTICE 			=> 'E_USER_NOTICE',
    					E_STRICT 				=> 'E_STRICT',
    					E_RECOVERABLE_ERROR 	=> 'E_RECOVERABLE_ERROR',
    				E_DEPRECATED 			=> 'E_DEPRECATED',
    					E_USER_DEPRECATED 	=> 'E_USER_DEPRECATED',
    					E_ALL 					=> 'E_ALL'
    			);
    
            /**
                 * A list of methods which are known to call the autoloader,
             * but shouldnot error, if theclass is not found.
             *
                 * They are allowed to fail, so we don't store a class not
                 * found exception if they do.
             */
                private static $SAFE_AUTOLOADER_FUNCTIONS = array(
                        'class_exists',
                        'interface_exists',
                        'method_exists',
                    'property_exists',
                        'is_subclass_of'
            );
    
                /**
                 * When returning values, if a mime type is set,
                 * then PHP Error should only output if the mime type
                 * is one of these.
                 */
                private static $ALLOWED_RETURN_MIME_TYPES = array(
                       'text/html',
                    'application/xhtml+xml'
                );
    
            private static function isIIS() {
                    return (
                                isset($_SERVER['SERVER_SOFTWARE']) &&
                            strpos($_SERVER['SERVER_SOFTWARE'], 'IIS/') !== false
                            ) || (
                                isset($_SERVER['_FCGI_X_PIPE_']) &&
                                    strpos($_SERVER['_FCGI_X_PIPE_'], 'IISFCGI') !== false
                        );
                }
    
                private static function isBinaryRequest() {
                    $response= ErrorHandler::getResponseHeaders();
  
                    foreach ( $response as $key =>$value ) {
                      if ( strtolower($key)=== 'content-transfer-encoding' ) {
                            return strtolower($value) === 'binary';
                        }
                }
                }
    
                /**
                 * This attempts to state if thisis *not* a PHP request,
                 * but it cannot say if it *is* a PHP request. It achieves
                 * this by looking for a mime type.
             *
                 * For example if the mime type is JavaScript, then we
                 * know it's not PHP. However there is no "yes,this is
             * definitely anormal HTML response" flag we can check.
                 */
                private static function isNonPHPRequest() {
                    /*
                     * Check if we are a mime type that isn't allowed.
                 * 
                     * If an allowed type is found, then we return false,
                     * as were are a PHP Request.
                 *
                 * Anything else found, returns true, as that means
                 * we are dealing with something unknown.
                 */
                    $response = ErrorHandler::getResponseHeaders();
    
                    foreach ( $response as $key => $value ) {
                     if ( strtolower($key) === 'content-type' ){
                            foreach ( ErrorHandler::$ALLOWED_RETURN_MIME_TYPES as $type ) {
                        if ( stripos($value, $type) !== false ) {
                                return false;
                        }
                        }
    
                            return true;
                        }
                }
    
                   return false;
                }
    
            /**
                 * Looks up a description for the symbol given,
                 * and if found, it is returned.
             *
                 * If it's not found, then the symbol given is returned.
                 */
                private static function phpSymbolToDescription( $symbol ) {
                    if ( isset(ErrorHandler::$PHP_SYMBOL_MAPPINGS[$symbol]) ) {
                     return ErrorHandler::$PHP_SYMBOL_MAPPINGS[$symbol];
                    } else {
                    return "'$symbol'";
                }
                }
    
             /**
                 * Attempts to syntax highlight the code snippet done.
             *
                 * This is then returned as HTML, ready to be dumped to the screen.
             *
                 * @param code An array of code lines to syntax highlight.
                * @return HTML version of the code given, syntax highlighted.
                 */
                private static function syntaxHighlight( $code ) {
                    $syntaxMap =ErrorHandler::$syntaxMap;
    
                    // @supress invalid code raises a warning
                $tokens = @token_get_all( "<?php " . $code . " ?" . ">" );
                 $html = array();
            $len = count($tokens)-1;
                    $inString = false;
                    $stringBuff = null;
                $skip = false;
    
                for ( $i = 1; $i < $len; $i++ ) {
                        $token = $tokens[$i];
    
                if ( is_array($token) ) {
                            $type = $token[0];
                            $code = $token[1];
                    } else {
                        $type = null;
                          $code = $token;
                        }
    
                        // work out any whitespace padding
                        if ( strpos($code, "\n") !== false && trim($code) === '' ) {
                            if ( $inString ) {
                                $html[]= "<span class='syntax-string'>" . join('', $stringBuff);
                                $stringBuff = array();
                        }
                        } else if ( $code === '&' ) {
                            if ( $i < $len ) {
                            $next = $tokens[$i+1];
    
                                if ( is_array($next) && $next[0] === T_VARIABLE ) {
                                $type = 'reference_ampersand';
                            }
                            }
                    } else if ( $code === '"' || $code === "'" ) {
                        if ( $inString ) {
                                $html[]= "<span class='syntax-string'>" . join('', $stringBuff) .htmlspecialchars($code) . "</span>";
                            $stringBuff = null;
                                $skip = true;
                        } else {
                                $stringBuff = array();
                        }
    
                            $inString = !$inString;
                    } else if ( $type === T_STRING ) {
                           $matches = array();
                    preg_match(ErrorHandler::REGEX_PHP_CONST_IDENTIFIER, $code, $matches);
    
                        if ( $matches && strlen($matches[0]) === strlen($code) ) {
                            $type = 'const';
                        }
                }
    
                        if ( $skip ){
                            $skip = false;
                        } else {
                        $code = htmlspecialchars( $code );
   
                     if ( $type !== null && isset($syntaxMap[$type]) ) {
                        $class = $syntaxMap[$type];
    
                                if ( $type === T_CONSTANT_ENCAPSED_STRING && strpos($code, "\n") !== false ) {
                            $append = "<span class='$class'>" .
                                                join(
                                                "</span>\n<span class='$class'>",
                                                        explode( "\n", $code )
                                            ) .
                                        "</span>" ;
                            } else if ( strrpos($code, "\n") === strlen($code)-1 ) {
                                $append = "<span class='$class'>" . substr($code, 0,strlen($code)-1) . "</span>\n";
                               } else {
                                    $append = "<span class='$class'>$code</span>";
                            }
                        } else if ( $inString && $code !== '"' ) {
                                $append = "<span class='syntax-string'>$code</span>";
                            } else {
                                $append = $code;
                            }
   
                            if ( $inString ) {
                           $stringBuff[]= $append;
                        } else {
                            $html[]= $append;
                        }
                }
                    }
    
                    if ( $stringBuff !== null ) {
                    $html[]= "<span class='syntax-string'>" . join('', $stringBuff) . '</span>';
                    $stringBuff = null;
                }

                return join( '', $html );
            }

            /**
             * Splits a given function name into it's 'class, function' parts.
             * If there is no class, then null is returned.
             *
             * It also returns these parts in an array of: array( $className, $functionName );
             *
             * Usage:
             *
             *      list( $class, $function ) = ErrorHandler::splitFunction( $name );
             *
             * @param name The function name to split.
             * @return An array containing class and function name.
             */
            private static function splitFunction( $name ) {
                $name = preg_replace( '/\\(\\)$/', '', $name );

                if ( strpos($name, '::') !== false ) {
                    $parts = explode( '::', $name );
                    $className = $parts[0];
                    $type = '::';
                    $functionName = $parts[1];
                } else if ( strpos($name, '->') !== false ) {
                    $parts = explode( '->', $name );
                    $className = $parts[0];
                    $type = '->';
                    $functionName = $parts[1];
                } else {
                    $className = null;
                    $type = null;
                    $functionName = $name;
                }

                return array( $className, $type, $functionName );
            }

            private static function newArgument( $name, $type=false, $isPassedByReference=false, $isOptional=false, $optionalValue=null, $highlight=false ) {
                if ( $name instanceof ReflectionParameter ) {
                        $highlight = func_num_args() > 1 ?
                                $highlight = $type :
                            false;
    
                        $klass = $name->getDeclaringClass();
                $functionName = $name->getDeclaringFunction()->name;
                        if ( $klass !== null ) {
                            $klass = $klass->name;
                    }
    
                        $export = ReflectionParameter::export(
                            ( $klass ?
                                        array( "\\$klass", $functionName ) :
                                    $functionName ),
                                $name->name,
                        true
                        );
    
                        $paramType = preg_replace('/.*?(\w+)\s+\$'.$name->name.'.*/', '\\1', $export);
                        if ( strpos($paramType, '[') !== false || strlen($paramType) === 0 ) {
                            $paramType = null;
                    }
    
                        return ErrorHandler::newArgument(
                                $name->name,
                            $paramType,
                            $name->isPassedByReference(),
                                $name->isDefaultValueAvailable(),
                        ( $name->isDefaultValueAvailable() ?
                                    var_export( $name->getDefaultValue(), true ) :
                                        null ),
                            ( func_num_args() > 1 ?
                                    $type :
                                    false )
                );
                    } else {
                    return array(
                                'name'              => $name,
                            'has_type'          => ( $type !== false ),
                                'type'              => $type,
                            'is_reference'      => $isPassedByReference,
                            'has_default'   => $isOptional,
                                'default_val'       => $optionalValue,
                            'is_highlighted'   => $highlight
                );
                  }
              }
 
                private static function syntaxHighlightFunctionMatch( $match, &$stackTrace, $highlightArg=null, &$numHighlighted=0 ) {
                    list( $className, $type, $functionName ) = ErrorHandler::splitFunction( $match );
    
                    // is class::method()
                    if ( $className !== null ) {
                        $reflectFun = new ReflectionMethod( $className,$functionName );
                // is a function
                    } else if ( $functionName === '{closure}' ) {
                    return '<span class="syntax-variable">$closure</span>';
                    } else {
                    $reflectFun =new ReflectionFunction( $functionName );
                    }
    
                    if ( $reflectFun ) {
                    $params = $reflectFun->getParameters();
    
                        if ( $params ) {
                    $args = array();
                            $min = 0;
                        foreach( $params as $i=> $param ) {
                                $arg = ErrorHandler::newArgument( $param );
    
                                if ( ! $arg['has_default'] ) {
                                $min = $i;
                         }
    
                                $args[]= $arg;
                            }
    
                        if ( $highlightArg !== null) {
                          for ( $i = $highlightArg; $i <= $min; $i++ ) {
                                $args[$i]['is_highlighted'] = true;
                            }
    
                                $numHighlighted = $min-$highlightArg;
                            }

                        if ( $className !== null) {
                                if ( $stackTrace && isset($stackTrace[1]) && isset($stackTrace[1]['type']) ) {
                            $type = htmlspecialchars( $stackTrace[1]['type'] );
                                }
                            } else {
                            $type =null;
                            }
    
                        return ErrorHandler::syntaxHighlightFunction( $className, $type, $functionName, $args );
                        }
                }
    
                    return null;
            }
    
                /**
         * Returns the values given, as HTML, syntax highlighted.
                 * It's a shorter, slightly faster, more no-nonsense approach
             * then 'syntaxHighlight'.
         *
                 * This is for syntax highlighting:
                 *  - fun( [args] )
                 *  - class->fun( [args] )
             *  - class::fun( [args] )
                 *
                 * Class and type can be null, to denote no class, but are not optional.
          */
                private static function syntaxHighlightFunction( $class, $type, $fun, &$args=null ) {
                $info = array();

                    // set the info
                if ( isset($class) && $class && isset($type) &&$type ) {
                       if ( $type === '->' ) {
                        $type = '-&gt;';
                        }
    
                    $info[]= "<span class='syntax-class'>$class</span>$type";
            }
    
                    if ( isset($fun) && $fun ) {
                $info []= "<span class='syntax-function'>$fun</span>";
                    }
    
                    if ( $args ) {
                    $info []= '( ';
    
                        foreach ($args as $i => $arg) {
                        if ( $i > 0 ) {
                                $info[]= ', ';
                        }
    
                            if ( is_string($arg) ) {
                                $info[]= $arg;
                            } else {
                            $highlight = $arg['is_highlighted'];
                            $name = $arg['name'];
    
                                if ( $highlight ) {
                                    $info[]='<span class="syntax-higlight-variable">';
                            }
    
                                if ( $name === '_' ) {
                            $info[]= '<spanclass="syntax-variable-not-important">';
                            }
    
                                if ( $arg['has_type'] ) {
                                    $info []= "<span class='syntax-class'>";
                                        $info []= $arg['type'];
                                $info []= '</span> ';
                                }
    
                            if ( $arg['is_reference'] ) {
                                $info []= '<span class="syntax-function">&amp;</span>';
                        }
    
                                $info []= "<span class='syntax-variable'>\$$name</span>";
    
                        if ( $arg['has_default'] ) {
                            $info []= '=<span class="syntax-literal">' . $arg['default_val'] . '</span>';
                            }
    
                                if ( $name ==='_' ) {
                                    $info[]= '</span>';
                                }
                            if ( $highlight ) {
                                    $info[]= '</span>';
                                }
                            }
                    }
    
                        $info []= ' )';
                } else {
                        $info []= '()';
                    }
    
                return join( '', $info );
                }
    
            /**
                 * Checks if the item is in options, and if it is, then it is removed and returned.
             *
              * If it is not found, or if options is not an array, then the alt is returned.
              */
                private static function optionsPop( &$options, $key, $alt=null ) {
                    if ( $options && isset($options[$key]) ) {
                        $val = $options[$key];
                        unset( $options[$key] );
    
                     return $val;
                    }else{
                        $iniAlt = @get_cfg_var( ErrorHandler::PHP_ERROR_INI_PREFIX . '.' . $key );
    
                        if ( $iniAlt !== false ) {
                            return $iniAlt;
                        } else {
                        return $alt;
                    }
                    }
                }

                private static function folderTypeToCSS( $type ) {
                    if ( $type === ErrorHandler::FILE_TYPE_ROOT ) {
                        return 'file-root';
                    } else if ( $type === ErrorHandler::FILE_TYPE_IGNORE ) {
                return 'file-ignore';
                    } else if ( $type === ErrorHandler::FILE_TYPE_APPLICATION ) {
                return 'file-app';
                    } else {
                        return 'file-common';
                    }
                }
    
            private static function isFolderType( &$folders, $longest, $file ) {
                $parts = explode( '/', $file );
    
                $len = min( count($parts), $longest );

                for ( $i = $len; $i > 0; $i-- ) {
                    if ( isset($folders[$i]) ) {
                        $folderParts = &$folders[ $i ];

                        $success = false;
                        for ( $j = 0; $j < count($folderParts); $j++ ) {
                            $folderNames = $folderParts[$j];

                            for ( $k = 0; $k < count($folderNames); $k++ ) {
                                if ( $folderNames[$k] === $parts[$k] ) {
                                    $success = true;
                                } else {
                                    $success = false;
                                    break;
                                }
                            }
                        }

                        if ( $success ) {
                            return true;
                        }
                    }
                }

                return false;
            }

            private static function setFolders( &$origFolders, &$longest, $folders ) {
                $newFolders = array();
                $newLongest = 0;

                if ( $folders ) {
                    if ( is_array($folders) ) {
                        foreach ( $folders as $folder ) {
                            ErrorHandler::setFoldersInner( $newFolders, $newLongest, $folder );
                        }
                    } else if ( is_string($folders) ) {
                        ErrorHandler::setFoldersInner( $newFolders, $newLongest, $folders );
                    } else {
                        throw new Exception( "Unknown value given for folder: " . $folders );
                    }
                }

                $origFolders = $newFolders;
                $longest     = $newLongest;
            }

            private static function setFoldersInner( &$newFolders, &$newLongest, $folder ) {
                $folder = str_replace( '\\', '/', $folder );
                $folder = preg_replace( '/(^\\/+)|(\\/+$)/', '', $folder );
                $parts  = explode( '/', $folder );
                $count  = count( $parts );

                $newLongest = max( $newLongest, $count );
    
                if ( isset($newFolders[$count]) ) {
                        $folds = &$newFolders[$count];
                    $folds[]= $parts;
                    } else {
                        $newFolders[$count] = array( $parts );
                }
                }
    
                private static function getRequestHeaders() {
                    if ( function_exists('getallheaders') ) {
                    return getallheaders();
                  } else {
                        $headers = array();
    
                        foreach ( $_SERVER as $key => $value ) {
                            if ( strpos($key, 'HTTP_') === 0 ) {
                            $key =str_replace( " ", "-", ucwords(strtolower( str_replace("_", " ", substr($key, 5)) )) );
                           $headers[ $key ] = $value;
                    }
                     }
    
                        return $headers;
                    }
            }
    
                private static function getResponseHeaders() {
            $headers = function_exists('apache_response_headers') ?
                            apache_response_headers() :
                            array() ;
    
                /*
                     * Merge the headers_list into apache_response_headers.
                 *
                     * This is because sometimes things are in one, which are
                 * not present in theother.
                     */
            if ( function_exists('headers_list') ){
                        $hList = headers_list();
    
                        foreach ($hList as $header) {
                         $header = explode(":", $header);
                        $headers[ array_shift($header) ] = trim( implode(":", $header) );
                        }
                }
    
                    return $headers;
                }
    
        public static function identifyTypeHTML( $arg, $recurseLevels=1 ) {
            if ( ! is_array($arg) && !is_object($arg) ) {
                   if ( is_string($arg) ) {
                    return "<span class='syntax-string'>&quot;" . htmlentities($arg) . "&quot;</span>";
                        } else {
                           return "<span class='syntax-literal'>" . var_export( $arg, true ) . '</span>';
                       }
                    } else if ( is_array($arg) ) {
                        if ( count($arg) === 0 ){
                            return "[]";
                        } else if ( $recurseLevels > 0 ) {
                            $argArr = array();
   
                        foreach ($arg as $ag) {
                            $argArr[]= ErrorHandler::identifyTypeHTML( $ag, $recurseLevels-1 );
                        }
    
                        if ( ($recurseLevels % 2) === 0 ) {
                                return "["  . join(', ', $argArr) .  "]";
                            } else {
                                return "[ " . join(', ', $argArr) . " ]";
                            }
                        } else {
                            return "[...]";
                        }
                } else if ( get_class($arg) === 'Closure' ) {
                        return '<span class="syntax-variable">$Closure</span>()';
                  } else {
                        $argKlass =get_class( $arg );
    
                        if ( preg_match(ErrorHandler::REGEX_PHP_CONST_IDENTIFIER, $argKlass) ) {
                            return '<span class="syntax-literal">$' . $argKlass . '</span>';
                   } else {
                            return '<span class="syntax-variable">$' . $argKlass . '</span>';
                        }
                    }
                }
    
               private $saveUrl;
                private $isSavingEnabled;
    
                private $cachedFiles;
   
                private $isShutdownRegistered;
                private $isOn;
    
                private $ignoreFolders = array();
             private $ignoreFoldersLongest = 0;
    
                private $applicationFolders = array();
                private $applicationFoldersLongest = 0;
    
                private $defaultErrorReportingOn;
                private $defaultErrorReportingOff;
                private $applicationRoot;
                private $serverName;
              private $showErrorCode;
    
                private $catchClassNotFound;
                private $catchSurpressedErrors;
            private $catchAjaxErrors;
    
                private $backgroundText;
                private $numLines;
 
                private $displayLineNumber;
                private $htmlOnly;
    
                private $isBufferSetup;
                private $bufferOutputStr;
                private $bufferOutput;
    
                private $isAjax;
    
                private $lastGlobalErrorHandler;
    
                private $classNotFoundException;
   
                /**
                 * = Options =
                 *
                 * All options are optional, and so is passing in an options item.
                 * You don't have to supply any, it's up to you.
                 *
                 * Notethat if 'php_error.force_disable' is true, then this object
             * will try to look like it works, but won't actually do anything.
                 *
                 * All options can also be passed in from 'php.ini'. You do this
                 * by setting it with 'php_error.' prefix. For example:
                 *
             *      php_error.catch_ajax_errors = On
                 *      php_error.error_reporting_on = E_ALL | E_STRICT
             *
                 * Includes:
                 *  = Types of errors this will catch =
             *  - catch_ajax_errors         When on, this will inject JS Ajax wrapping code, to allow this to catch any future JSON errors. Defaults to true.
                 *  - catch_supressed_errors    The @ supresses errors. If set to true, then they are still reported anyway, but respected when false. Defaults to false.
             *  - catch_class_not_found     When true, loading a class that does notexist will be caught. This defaults to true.
                 *
                 *  = Error reporting level =
                 *  - error_reporting_on        value for when errors are on, defaults to all errors
             *  - error_reporting_off       value for when errors are off, defaults to php.ini's error_reporting.
                 *
                 *  = Setup Details =
                 *  - application_root          When it's working out the stacktrace, this is the root folder of the application, to use as it's base.
                 *                              Defaults to the servers root directory.
                 *
                 *                            A relative path can be given, but lets be honest, an explicit path is the way to guarantee that you
                 *                          will get the path you want. My relative might not be the same as your relative.
                 *
                *  - snippet_num_lines         The number of lines to display in the code snippet.
             *                          That includes the line being reported.
             *
             *  - server_name               The name for this server, defaults to "$_SERVER['SERVER_NAME']"
         *
                 *  - ignore_folders            This is allows you to highlight non-framework code in a stack trace.
             *                             An array of folders toignore, when working out the stack trace.
                 *                          This is folder prefixes in relation to the application_root, whatever that might be.
                 *                              They are onlyignored if there is a file found outside of them.
                 *                              If you still don't getwhatthis does, don't worry, it's here cos I use it.
             *
                 *  - application_folders       Just like ignore, but anything found in these folders takes precedence
             *                              over anything else.
                 *
                 *  - background_text           The text that appeares in the background. By default this is blank.
         *                              Why? You can replace this with the name of your framework, for extra customization spice.
                 *
                 *  - html_only                 By default, PHP Error only runs on ajax and HTML pages.
                 *                              If this is false, then it will also run when on non-HTML
                 *                          pages too, such as replying with images of JavaScript
                 *                              from your PHP. Defaults totrue.
             *
             *  - file_link                 When true, files are linked to from the CSS Stack trace, allowing you to open them.
             *                              Defaults to true.
             *
             *  - save_url                  The url of where to send files, to be saved.
             *                              Note that 'enable_saving' must be on for this to be used (which it is by default).
             *
             *  - enable_saving             Can be true or false. When true, saving files is enabled, and when false, it is disabled.
             *                              Defaults to true!
             *
             * @param options Optional, an array of values to customize this handler.
             * @throws Exception This is raised if given an options that does *not* exist (so you know that option is meaningless).
             */
            public function __construct( $options=null ) {
                // there can only be one to rule them all
                global $_php_error_global_handler;
                if ( $_php_error_global_handler !== null ) {
                    $this->lastGlobalErrorHandler = $_php_error_global_handler;
                } else {
                    $this->lastGlobalErrorHandler = null;
                }
                $_php_error_global_handler = $this;

                $this->cachedFiles = array();

                $this->isShutdownRegistered = false;
                $this->isOn = false;

                /*
                 * Deal with the options.
                 *
                 * They are removed one by one, and any left, will raise an error.
                 */

                $ignoreFolders                  = ErrorHandler::optionsPop( $options, 'ignore_folders'     , null );
                $appFolders                     = ErrorHandler::optionsPop( $options, 'application_folders', null );

                if ( $ignoreFolders !== null ) {
                    ErrorHandler::setFolders( $this->ignoreFolders, $this->ignoreFoldersLongest, $ignoreFolders );
                }
                if ( $appFolders !== null ) {
                    ErrorHandler::setFolders( $this->applicationFolders, $this->applicationFoldersLongest, $appFolders );
                }

                $this->saveUrl                  = ErrorHandler::optionsPop( $options, 'save_url', $_SERVER['REQUEST_URI'] );
                $this->isSavingEnabled          = ErrorHandler::optionsPop( $options, 'enable_saving', true );

                $this->defaultErrorReportingOn  = ErrorHandler::optionsPop( $options, 'error_reporting_on'  , -1                        );
                $this->defaultErrorReportingOff = ErrorHandler::optionsPop( $options, 'error_reporting_off' , error_reporting()         );

                $this->applicationRoot          = ErrorHandler::optionsPop( $options, 'application_root'    , $_SERVER['DOCUMENT_ROOT'] );
                    $this->serverName         = ErrorHandler::optionsPop( $options, 'server_name'         , $_SERVER['SERVER_NAME']   );
                  $this->showErrorCode            = ErrorHandler::optionsPop( $options, 'show_error_code'         ,false);
    
                /*
                     * Relative paths might be given for document root,
                 * so we make it explicit.
                 */
                    $dir = @realpath( $this->applicationRoot );
                if ( ! is_string($dir) ) {
                   throw new Exception("Document root not found: " . $this->applicationRoot);
                } else {
                        $this->applicationRoot =  str_replace( '\\', '/', $dir );
                }
    
                $this->catchClassNotFound       = !! ErrorHandler::optionsPop( $options, 'catch_class_not_found' , true  );
                $this->catchSurpressedErrors    = !! ErrorHandler::optionsPop( $options, 'catch_supressed_errors', false );
                $this->catchAjaxErrors          = !! ErrorHandler::optionsPop( $options, 'catch_ajax_errors'     , true  );
    
                $this->backgroundText           = ErrorHandler::optionsPop( $options, 'background_text'       , '' );
                    $this->numLines               = ErrorHandler::optionsPop( $options, 'snippet_num_lines'     , ErrorHandler::NUM_FILE_LINES        );
                    $this->displayLineNumber        = ErrorHandler::optionsPop( $options, 'display_line_numbers'  , true );
    
                    $this->htmlOnly             = !! ErrorHandler::optionsPop( $options, 'html_only', true );
    
            $this->classNotFoundException   = null;
    
                    $wordpress = ErrorHandler::optionsPop( $options, 'wordpress', false );
                    if ( $wordpress ) {
                       // php doesn't like | in constants and privates, so just set it directly : (
                    $this->defaultErrorReportingOn = E_ERROR | E_WARNING | E_PARSE | E_USER_DEPRECATED & ~E_DEPRECATED & ~E_STRICT;
            }
    
                $concrete5 = ErrorHandler::optionsPop( $options,'concrete5', false );
                    if ( $concrete5 ) {
                       $this->defaultErrorReportingOn = E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED;
                }
    
                    if ( $options ) {
                    foreach ( $options as $key => $val ) {
                        throw newInvalidArgumentException( "Unknown option given $key" );
                        }
            }
    
                    $this->isAjax = (
                        isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) &&
                                ( $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest' )
                        ) || (
                                isset( $_REQUEST['php_error_is_ajax'] )
                            );
    
                $this->isBufferSetup = false;
                $this->bufferOutputStr = '';
                $this->bufferOutput = false;
    
                    $this->startBuffer();
                }
    
            /*
                 * --- --- --- --- ------ --- --- --- --- --- --- --- --- --- --- ---
                 * Public Functions
                 * ------ --- --- --- --- --- --- --- --- --- --- --- --- --- --- ---
             */
    
                /**
                 * @return trueif this is currently on, false if not.
                 */
                public function isOn() {
                    return $this->isOn;
                }
    
                /**
                 * @return Ifthis is off, this returns true, otherwise false.
                 */
            public function isOff() {
                return !$this->isOn;
            }
    
                /**
         * Turns error reporting on.
             *
              * This will use the strictest error reporting available, or the
                 * level you pass in when creating this using the 'error_reporting_on'
                 * option.
             *
                 * @return This error reporting handler, for method chaining.
                 */
                public function turnOn() {
                $this->propagateTurnOff();
                $this->setEnabled( true );
    
            /*
                     * Check if file changes have been uploaded,
                     * and if so, save them.
                     */
              global $_php_error_is_ini_enabled;
                  if ( $_php_error_is_ini_enabled) {
                        if ( $this->isSavingEnabled ) {
                            $headers = ErrorHandler::getRequestHeaders();
    
                            if ( isset($headers[ErrorHandler::HEADER_SAVE_FILE]) ) {
                            if ( isset($_POST) && isset($_POST[ErrorHandler::POST_FILE_LOCATION]) ) {
                                    $files = $_POST[ErrorHandler::POST_FILE_LOCATION];
    
                                foreach ( $files as $file => $content ) {
                                    @file_put_contents( $file, stripcslashes($content) );
                                }
    
                            exit(0);
                       }
                        }
                        }
                    }
    
                    return $this;
                }
    
            /**
                 * Turns error reporting off.
                 *
             * This will use the 'php.ini' setting for the error_reporting level,
                 * or one you have passed in if you used the 'error_reporting_off'
                 * option when creating this.
             *
                 * @return This error reporting handler,for method chaining.
                 */
                public function turnOff() {
                $this->setEnabled( false );
    
                    return $this;
                }
    
            /**
                 * Allows you to run a callback with strict errors turned off.
                 * Standard errors still apply, but this will use thedefault
                 * error and exception handlers.
             *
                 * This is useful for when loading libraries which do not
                 * adhere to strict errors, such as Wordpress.
                 *
                 * To use:
                 *
                 *  withoutErrors( function() {
               *          // unsafe code here
                 *    });
                 *
                 * This will use the error_reporting value for when this is
                 * turned off.
         *
                 * @param callback A PHP function to call.
                 * @return The result of calling the callback.
                 */
                public function withoutErrors( $callback ) {
                    if ( ! is_callable($callback) ) {
                throw new Exception( "non callable callback given" );
                    }
   
                    if ( $this->isOn() ) {
                    $this->turnOff();
                        $result = $callback();
                        $this->turnOn();
    
                        return $result;
                    } else {
                    return $callback();
                    }
            }
    
                /**
             * This is the shutdown function, which should *only* be called
                * via 'register_shutdown_function'.
             *
                 * It's exposed because it has to be exposed.
             */
            public function __onShutdown() {
                global $_php_error_is_ini_enabled;
    
                    if ( $_php_error_is_ini_enabled ) {
                     if ( $this->isOn() ) {
                    $error = error_get_last();
    
                        // fatal and syntax errors
                        if (
                                $error && (
                                            $error['type']===  1 ||
                                            $error['type'] ===  4 ||
                                        $error['type']=== 64
                                    )
                    ) {
                            $this->reportError( $error['type'], $error['message'], $error['line'], $error['file'] );
                    } else {
                                $this->endBuffer();
                        }
                    } else {
                        $this->endBuffer();
                    }
                }
            }

            /*
             * --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- ---
             * Private Functions
             * --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- ---
             */

            private function propagateTurnOff() {
                if ( $this->lastGlobalErrorHandler !== null ) {
                    $this->lastGlobalErrorHandler->turnOff();
                    $this->lastGlobalErrorHandler->propagateTurnOff();
                    $this->lastGlobalErrorHandler = null;
                }
            }

            /**
             * This is intended to be used closely with 'onShutdown'.
             * It ensures that output buffering is turned on.
             *
             * Why? The user may output content, and *then* hit an error.
             * We cannot replace the page if this happens,
             * because they have already outputted information.
             *
             * So we buffer the page, and then output at the end of the page,
             * or when an error strikes.
             */
            private function startBuffer() {
                global $_php_error_is_ini_enabled;

                if ( $_php_error_is_ini_enabled && !$this->isBufferSetup ) {
                    $this->isBufferSetup = true;

                    ini_set( 'implicit_flush', false );
                    ob_implicit_flush( false );

                    if ( ! @ini_get('output_buffering') ) {
                        @ini_set( 'output_buffering', 'on' );
                    }

                    $output = '';
                    $bufferOutput = true;

                    $this->bufferOutputStr  = &$output;
                    $this->bufferOutput = &$bufferOutput;

                    ob_start( function($string) use (&$output, &$bufferOutput) {
                        if ( $bufferOutput ) {
                            $output .= $string;
                            return '';
                        } else {
                            $temp = $output . $string;
                            $output = '';
                            return $temp;
                        }
                    });

                    $self = $this;
                    register_shutdown_function( function() use ( $self ) {
                        $self->__onShutdown();
                    });
                }
            }

            /**
             * Turns off buffering, and discards anything buffered
             * so far.
             *
             * This will return what has been buffered incase you
             * do want it. However otherwise, it will be lost.
             */
            private function discardBuffer() {
                $str = $this->bufferOutputStr;

                $this->bufferOutputStr = '';
                $this->bufferOutput = false;

                return $str;
            }

            /**
             * Flushes the internal buffer,
             * outputting what is left.
         *
             * @param append Optional, extra content to append onto the output buffer.
             */
                private function flushBuffer() {
                    $temp = $this->bufferOutputStr;
                    $this->bufferOutputStr= '';
    
                    return $temp;
            }
    
        /**
                 * This will finish buffering, and output the page.
                 * It also appends the magic JS onto the beginning of the page,
                 * if enabled, to allow working with Ajax.
               *
                 * Note that if PHP Error has been disabled in the php.ini file,
                 * or through some other option,such as running from the command line,
                 * then this will do nothing (as no buffering will take place).
                */
                public function endBuffer() {
                   if ( $this->isBufferSetup ) {
                        $content  = ob_get_contents();
                        $handlers = ob_list_handlers();
    
                    $wasGZHandler = false;
    
                    $this->bufferOutput = true;
                        for ( $i = count($handlers)-1;$i >= 0; $i-- ) {
                        $handler = $handlers[$i];
    
                        if ( $handler=== 'ob_gzhandler' ) {
                                $wasGZHandler = true;
                                ob_end_clean();
                            } else if ( $handler === 'default output handler' ) {
                            ob_end_clean();
                            } else if ( $handler === 'zlib output compression' ) {
                                if (ob_get_level()) {
                                while (@ob_end_clean());
                            }
                        } else {
                             ob_end_flush();
                            }
                    }
    
                        $content = $this->discardBuffer();
    
                    if ( $wasGZHandler ) {
                        ob_start('ob_gzhandler');
                    } else {
                            ob_start();
                        }
    
                    if (
                         !$this->isAjax &&
                             $this->catchAjaxErrors &&
                             (!$this->htmlOnly || !ErrorHandler::isNonPHPRequest()) &&
                             !ErrorHandler::isBinaryRequest()
                    ) {
                    $js = $this->getContent( 'displayJSInjection' );
                    
                            // attemp to inject the script into the HTML, after the doctype
                            $matches = array();
                  preg_match( ErrorHandler::REGEX_DOCTYPE, $content, $matches );
   
                            if ( $matches ) {
                            $doctype = $matches[0];
                               $content = preg_replace( ErrorHandler::REGEX_DOCTYPE, "$doctype $js", $content );
                    } else {
                                echo $js;
                        }
                    }
    
                        echo $content;
            }
                }
    
            /**
                 * Calls the given method on this object,
                 * captures it's output, and then returns it.
                 *
                 * @param method The name of themethod to call.
                 * @return All of the textoutputted during the method call.
             */
                private function getContent( $method ) {
                    ob_start();
                    $this->$method();
                $content = ob_get_contents();
                    ob_end_clean();
    
                   return $content;
                }

                private function isApplicationFolder( $file ) {
                    return ErrorHandler::isFolderType(
                            $this->applicationFolders,
                        $this->applicationFoldersLongest,
                            $file
                );
                }
    
                private function isIgnoreFolder( $file ) {
                    return ErrorHandler::isFolderType(
                        $this->ignoreFolders,
                            $this->ignoreFoldersLongest,
                            $file
                    );
            }
    
                private function getFolderType( $root, $file ) {
                    $testFile = $this->removeRootPath( $root,$file );
    
                    // it's this file : (
                    if ( $file === __FILE__ ) {
                $type = ErrorHandler::FILE_TYPE_IGNORE;
                    } else if ( strpos($testFile, '/') === false ) {
                        $type = ErrorHandler::FILE_TYPE_ROOT;
                    } else if ( $this->isApplicationFolder($testFile) ) {
                      $type = ErrorHandler::FILE_TYPE_APPLICATION;
                    } else if ( $this->isIgnoreFolder($testFile) ) {
                        $type = ErrorHandler::FILE_TYPE_IGNORE;
                    } else {
                    $type = false;
                    }
    
                return array( $type, $testFile );
                }
    
                /**
                 * Finds the file named,and returns it's contents in an array.
                 *
                 * It's essentially the same as 'file_get_contents'. However
             * this will add cachingat this PHP layer, avoiding lots of
                 * duplicate calls.
             *
                 * It also splits the file into an array of lines, and makes
                 * it html safe.
                 *
             * @param path The file to get the contents of.
             * @return The file we are after, as an array of lines.
                 */
            private function getFileContents( $path ) {
                if ( isset($this->cachedFiles[$path]) ) {
                        return $this->cachedFiles[$path];
                    } else {
                    $contents = @file_get_contents( $path );
    
                    if ( $contents ) {
                            $contents = explode(
                                "\n",
                                preg_replace(
                                    '/(\r\n)|(\n\r)|\r/',
                                        "\n",
                                            str_replace( "\t", ' ', $contents )
                                    )
                    );
    
                            $this->cachedFiles[ $path ] = $contents;
    
                            return $contents;
                        }
                    }
    
                    return array();
                }
    
                /**
                 * Reads out the code from the section of the line,
             *which is at fault.
                 *
             * The array is in a mapping of:array( line-number => line )
             *
           * If something goes wrong, thennull is returned.
             */
            private function readCodeFile( $errFile, $errLine ) {
                try {
                        $lines = $this->getFileContents( $errFile );
    
                    if ( $lines ) {
                            $numLines = $this->numLines;
    
                            $searchUp   = ceil( $numLines*0.75 );
                            $searchDown = $numLines - $searchUp;
    
                    $countLines = count( $lines );
    
                            /*
                         * Search around the errLine.
                         * We should aim get half of the lines above, and half from below.
                             * If that fails we get as many as we can.
                         */

                        /*
                         * If we are near the bottom edge,
                         * we go down as far as we can,
                         * then work up the search area.
                         */
                        if ( $errLine+$searchDown > $countLines ) {
                            $minLine = max( 0, $countLines-$numLines );
                            $maxLine = $countLines;
                        /*
                         * Go up as far as we can, up to half the search area.
                         * Then stretch down the whole search area.
                         */
                        } else {
                            $minLine = max( 0, $errLine-$searchUp );
                            $maxLine = min( $minLine+$numLines, count($lines) );
                        }

                        $fileLines = array_splice( $lines, $minLine, $maxLine-$minLine );

                        $stripSize = -1;
                        foreach ( $fileLines as $i => $line ) {
                            $newLine = ltrim( $line, ' ' );

                            if ( strlen($newLine) > 0 ) {
                                $numSpaces = strlen($line) - strlen($newLine);

                                if ( $stripSize === -1 ) {
                                    $stripSize = $numSpaces;
                                } else {
                                    $stripSize = min( $stripSize, $numSpaces );
                                }
                            } else {
                                $fileLines[$i] = $newLine;
                            }
                        }
                        if ( $stripSize > 0 ) {
                            /*
                             * It's pretty common that PHP code is not flush with the left hand edge,
                             * so subtract 4 spaces, if we can,
                             * to account for this.
                             */
                            if ( $stripSize > 4 ) {
                                $stripSize -= 4;
                            }

                            foreach ( $fileLines as $i => $line ) {
                                if ( strlen($line) > $stripSize ) {
                                    $fileLines[$i] = substr( $line, $stripSize );
                                }
                            }
                        }

                        $fileLines = join( "\n", $fileLines );
                        $fileLines = ErrorHandler::syntaxHighlight( $fileLines );
                        $fileLines = explode( "\n", $fileLines );

                        $lines = array();
                        for ( $i = 0; $i < count($fileLines); $i++ ) {
                            // +1 is because line numbers start at 1, whilst arrays start at 0
                            $lines[ $i+$minLine+1 ] = $fileLines[$i];
                        }
                    }

                    return $lines;
                } catch ( Exception $ex ) {
                    return null;
                }

                return null;
            }

            /**
             * Attempts to remove the root path from the path given.
             * If the path can't be removed, then the original path is returned.
             *
             * For example if root is 'C:/users/projects/my_site',
             * and the file is 'C:/users/projects/my_site/index.php',
             * then the root is removed, and we are left with just 'index.php'.
             *
             * This is to remove line noise; you don't need to be told the
             * 'C:/whatever' bit 20 times.
             *
             * @param root The root path to remove.
             * @param path The file we are removing the root section from.
             */
            private function removeRootPath( $root, $path ) {
                $filePath = str_replace( '\\', '/', $path );
    
                    if (
                            strpos($filePath, $root) === 0 &&
                        strlen($root) < strlen($filePath)
                    ) {
                    return substr($filePath, strlen($root)+1 );
                  } else {
                    return $filePath;
                }
                }
    
            /**
                 * Parses, and alters, the errLine, errFile andmessage given.
                 *
                 * This includes adding syntax highlighting,removing duplicate
                 * information we already have, and making the error easier to
                 * read.
                 */
           private function improveErrorMessage( $ex, $code, $message, $errLine, $errFile, $root, &$stackTrace ) {
                // change these to change where the source file is come from
                    $srcErrFile = $errFile;
                    $srcErrLine = $errLine;
                  $altInfo = null;
                    $stackSearchI = 0;
    
                $skipStackFirst = function( &$stackTrace ) {
                        $skipFirst = true;
    
                    foreach ( $stackTrace as $i => $trace ) {
                             if ( $skipFirst ) {
                                  $skipFirst = false;
                             } else {
                              if ( $trace && isset($trace['file']) && isset($trace['line']) ) {
                                    return array( $trace['file'], $trace['line'], $i );
                                  }
                            }
                        }
    
                        return array( null, null, null );
            };
    
                    /*
                     * This is for calling a function that doesn't exist.
                 *
                 * The message contains along description of where this takes
                     * place, even though we are already told this through line and
                     * file info. So we cut it out.
                     */
                if ( $code === 1 ) {
                        if (
                            ( strpos($message, " undefined method ") !== false ) ||
                            ( strpos($message, " undefined function ") !== false )
                    ) {
                        $matches = array();
                            preg_match( '/\b[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*((->|::)[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)?\\(\\)$/', $message, $matches );
    
                            /*
                             * undefined function or method call
                         */
                            if ( $matches ) {
                        list( $className, $type,$functionName ) = ErrorHandler::splitFunction( $matches[0] );
    
                            if ( $stackTrace && isset($stackTrace[1]) && $stackTrace[1]['args'] ) {
                                $numArgs = count( $stackTrace[1]['args'] );
    
                                for ( $i = 0; $i < $numArgs; $i++ ) {
                                    $args[]= ErrorHandler::newArgument( "_" );
                                    }
                            }
    
                        $message = preg_replace(
                                        '/\b[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*((->|::)[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)?\\(\\)$/',
                                        ErrorHandler::syntaxHighlightFunction( $className, $type, $functionName, $args ),
                                    $message
                                );
                            }
                        } else if ( $message === 'Using $this whennot in object context' ) {
                        $message = 'Using <span class="syntax-variable">$this</span> outside object context';
                       /*
                     * Class not found error.
                         */
                        }else if (
                        strpos($message, "Class ") !== false &&
                            strpos($message, "not found") !== false
                        ) {
                            $matches = array();
                         preg_match( '/\'(\\\\)?[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*((\\\\)?[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)+\'/', $message, $matches );
    
                            if( count($matches) > 0 ) {
                                // lose the 'quotes'
                        $className = $matches[0];
                            $className = substr( $className, 1, strlen($className)-2 );
    
                                $message = preg_replace(
                                        '/\'(\\\\)?[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*((\\\\)?[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)+\'/',
                                    "<span class='syntax-class'>$className</span>",
                                    $message
                            );
                            }
                     }
                    } else if ( $code === 2 ) {
                    if ( strpos($message, "Missing argument ") === 0 ) {
                        $message= preg_replace( '/, called in .*$/', '', $message );
    
                            $matches = array();
                        preg_match( ErrorHandler::REGEX_METHOD_OR_FUNCTION_END, $message, $matches );
    
                    if ( $matches ) {
                            $argumentMathces = array();
                            preg_match( '/^Missing argument ([0-9]+)/', $message, $argumentMathces );
                                $highlightArg = count($argumentMathces) === 2 ?
                                        (((int) $argumentMathces[1])-1) :
                                null ;
    
                            $numHighlighted = 0;
                                $altInfo = ErrorHandler::syntaxHighlightFunctionMatch( $matches[0], $stackTrace, $highlightArg, $numHighlighted );
    
                            if ( $numHighlighted > 0 ) {
                                $message = preg_replace( '/^Missing argument ([0-9]+)/', 'Missing arguments ', $message );
                                }
    
                            if ( $altInfo ) {
                                $message = preg_replace( ErrorHandler::REGEX_METHOD_OR_FUNCTION_END, $altInfo, $message );
   
                                    list( $srcErrFile, $srcErrLine, $stackSearchI ) = $skipStackFirst( $stackTrace );
                                }
                            }
                        } else if (
                            strpos($message, 'require(') === 0 ||
                            strpos($message, 'include(') === 0
                        ) {
                            $endI  = strpos( $message, '):' );
    
                        if ( $endI ) {
                            // include( is thesame length
                                $requireLen = strlen('require(');
    
                            /*
                             * +2 to include the ): at the end of the string
                             */
                            $postMessage = substr( $message, $endI+2 );
                            $postMessage = str_replace( 'failed to open stream: No ', 'no ', $postMessage );
                            $message = substr_replace( $message, $postMessage, $endI+2 );

                            /*
                             * If this string is in there, and where we think it should be,
                             * swap it with a shorter message.
                             */
                            $replaceBit = 'failed to open stream: No ';
                            if ( strpos($message, $replaceBit) === $endI+2 ) {
                                $message  = substr_replace( $message, 'no ', $endI+2, strlen($replaceBit) );
                            }

                            /*
                             * Now put the string highlighting in there.
                             */
                            $match = substr( $message, $requireLen, $endI-$requireLen );
                            $newString = "<span class='syntax-string'>'$match'</span>),";
                            $message  = substr_replace( $message, $newString, $requireLen, ($endI-$requireLen)+2 );
                        }
                    }
                /*
                 * Unexpected symbol errors.
                 * For example 'unexpected T_OBJECT_OPERATOR'.
                 *
                 * This swaps the 'T_WHATEVER' for the symbolic representation.
                 */
                } else if ( $code === 4 ) {
                    if ( $message === "syntax error, unexpected T_ENCAPSED_AND_WHITESPACE" ) {
                        $message = "syntax error, string is not closed";
                    } else {
                        $semiColonError = false;
                        if ( strpos($message, 'syntax error,') === 0 && $errLine > 2 ) {
                            $lines = ErrorHandler::getFileContents( $errFile );

                            $line = $lines[$errLine-1];
                            if ( preg_match( ErrorHandler::REGEX_MISSING_SEMI_COLON_FOLLOWING_LINE, $line ) !== 0 ) {
                                $content = rtrim( join( "\n", array_slice($lines, 0, $errLine-1) ) );

                                if ( strrpos($content, ';') !== strlen($content)-1 ) {
                                    $message = "Missing semi-colon";
                                    $errLine--;
                                    $srcErrLine = $errLine;
                                    $semiColonError = true;
                                }
                            }
                        }

                        if ( $semiColonError ) {
                            $matches = array();
                            $num = preg_match( '/\bunexpected ([A-Z_]+|\\$end)\b/', $message, $matches );

                            if ( $num > 0 ) {
                                $match = $matches[0];
                                $newSymbol = ErrorHandler::phpSymbolToDescription( str_replace('unexpected ', '', $match) );

                                $message = str_replace( $match, "unexpected $newSymbol", $message );
                            }

                            $matches = array();
                            $num = preg_match( '/, expecting ([A-Z_]+|\\$end)( or ([A-Z_]+|\\$end))*/', $message, $matches );

                            if ( $num > 0 ) {
                                $match = $matches[0];
                                $newMatch = str_replace( ", expecting ", '', $match );
                                $symbols = explode( ' or ', $newMatch );
                                foreach ( $symbols as $i => $sym ) {
                                    $symbols[$i] = ErrorHandler::phpSymbolToDescription( $sym );
                                }
                                $newMatch = join( ', or ', $symbols );

                                $message = str_replace( $match, ", expecting $newMatch", $message );
                            }
                        }
                    }
                /**
                 * Undefined Variable, add syntax highlighting and make variable from 'foo' too '$foo'.
                 */
                } else if ( $code === 8 ) {
                    if (
                        strpos($message, "Undefined variable:") !== false
                    ) {
                        $matches = array();
                        preg_match( ErrorHandler::REGEX_VARIABLE,$message, $matches );
    
                        if ( count($matches) > 0 ) {
                                $message = 'Undefined variable <span class="syntax-variable">$' . $matches[0] . '</span>' ;
                        }
                        }
                /**
                 * Invalid type given.
                     */
                    } else if ( $code === 4096 ) {
                        if ( strpos($message, 'must be an ') ) {
                        $message = preg_replace( '/, called in .*$/', '', $message );
    
                        $matches = array();
                      preg_match( ErrorHandler::REGEX_METHOD_OR_FUNCTION, $message, $matches );
    
                        if ( $matches ) {
                                $argumentMathces = array();
                        preg_match( '/^Argument ([0-9]+)/', $message, $argumentMathces );
                                $highlightArg = count($argumentMathces) === 2 ?
                                    (((int) $argumentMathces[1])-1) :
                                    null ;
    
                                $fun = ErrorHandler::syntaxHighlightFunctionMatch( $matches[0], $stackTrace, $highlightArg );
    
                                if ( $fun ) {
                                    $message = str_replace( 'passed to ', 'calling ', $message );
                                    $message = preg_replace( ErrorHandler::REGEX_METHOD_OR_FUNCTION, $fun, $message );
                                $prioritizeCaller = true;
    
                                /*
                                 * scalars not supported.
                             */
                                    $scalarType = null;
                        if ( ! ErrorHandler::$IS_SCALAR_TYPE_HINTING_SUPPORTED ) {
                                    foreach ( ErrorHandler::$SCALAR_TYPES as $scalar ) {
                                        if ( stripos($message, "must be an instance of $scalar,") !== false ) {
                                        $scalarType = $scalar;
                                        break;
                                            }
                                        }
                                }
    
                                if ( $scalarType !==null ) {
                                        $message = preg_replace( '/^Argument [0-9]+ calling /', 'Incorrect type hinting for ', $message );
                                $message = preg_replace(
                                            '/ must be an instance of ' . ErrorHandler::REGEX_PHP_IDENTIFIER . '\b.*$/',
                                            ", ${scalarType} is not supported",
                                                $message
                                    );
    
                                    $prioritizeCaller = false;
                              } else {
                                      $message = preg_replace( '/ must be an (instance of )?' . ErrorHandler::REGEX_PHP_IDENTIFIER . '\b/', '', $message );
    
                                    if ( preg_match('/, none given$/', $message) ) {
                                        $message = preg_replace( '/^Argument /', 'Missing argument ', $message );
                                        $message = preg_replace( '/, none given$/', '', $message );
                                        } else {
                                        $message = preg_replace( '/^Argument /', 'Incorrect argument ', $message );
                                    }
                                }
    
                                if ( $prioritizeCaller ) {
                                    list( $srcErrFile, $srcErrLine, $stackSearchI ) = $skipStackFirst( $stackTrace );
                                 }
                                }
                            }
                        }
                }
    
                if ( $stackTrace !== null ) {
                $isEmpty = count( $stackTrace ) === 0 ;
    
                       if ( $isEmpty ) {
                            array_unshift( $stackTrace, array(
                                'line' => $errLine,
                                    'file' => $errFile
                        ) );
                        } else if (
                                count($stackTrace)> 0 && (
                                        (! isset($stackTrace[0]['line'])) ||
                                    ($stackTrace[0]['line'] !== $errLine)
                            )
                        ) {
                            array_unshift( $stackTrace, array(
                                    'line' => $errLine,
                                'file' => $errFile
                            ) );
                        }
   
                    if ( $stackTrace && !$isEmpty ) {
                        $ignoreCommons = false;
                           $len = count($stackTrace);
    
                            /*
                         * The code above can prioritize a location in the stack trace,
                         * this is 'stackSearchI'. So we should start our search from there,
                     * and work down the stack.
                         *
                             * This is built in a way so that when it reaches the end, it'll loop
                         * back round to the beginning, and check the traces we didn't check
                             * last time.
                             *
                         * If stackSearchI was not altered, then it just searches from top
                             * through to the bottom.
                             */
                        for ( $i = $stackSearchI;$i < $stackSearchI+$len; $i++ ) {
                        $trace = &$stackTrace[ $i % $len ];
    
                            if ( isset($trace['file']) && isset($trace['line']) ) {
                             list( $type, $_ ) = $this->getFolderType( $root, $trace['file'] );
    
                                    if ( $type !== ErrorHandler::FILE_TYPE_IGNORE ) {
                                    if ($type === ErrorHandler::FILE_TYPE_APPLICATION ) {
                                        $srcErrLine = $trace['line'];
                                         $srcErrFile = $trace['file'];
    
                                        break;
                                    } else if ( ! $ignoreCommons ) {
                                        $srcErrLine = $trace['line'];
                                        $srcErrFile = $trace['file'];

                                        $ignoreCommons = true;
                                    }
                                }
                            }
                        }
                    }
                }

                return array( $message, $srcErrFile, $srcErrLine, $altInfo );
            }

            /**
             * Parses the stack trace, and makes it look pretty.
             *
             * This includes adding in the syntax highlighting,
             * highlighting the colours for the files,
             * and padding with whitespace.
             *
             * If stackTrace is null, then null is returned.
             */
            private function parseStackTrace( $code, $message, $errLine, $errFile, &$stackTrace, $root, $altInfo=null ) {
                if ( $stackTrace !== null ) {
                    /*
                     * For whitespace padding.
                     */
                    $lineLen = 0;
                    $fileLen = 0;

                    // parse the stack trace, and remove the long urls
                    foreach ( $stackTrace as $i => $trace ) {
                        if ( $trace ) {
                            if ( isset($trace['line'] ) ) {
                                $lineLen = max( $lineLen, strlen($trace['line']) );
                            } else {
                                $trace['line'] = '';
                            }

                            $info = '';

                            if ( $i === 0 && $altInfo !== null ) {
                                $info = $altInfo;
                            /*
                             * Skip for the first iteration,
                             * as it's usually magical PHP calls.
                             */
                            } else if (
                                $i > 0 && (
                                        isset($trace['class']) ||
                                        isset($trace['type']) ||
                                        isset($trace['function'])
                                )
                            ) {
                                $args = array();
                                if ( isset($trace['args']) ) {
                                    foreach ( $trace['args'] as $arg ) {
                                        $args[]= ErrorHandler::identifyTypeHTML( $arg, 1 );
                                    }
                                }

                                $info = ErrorHandler::syntaxHighlightFunction(
                                        isset($trace['class'])      ? $trace['class']       : null,
                                        isset($trace['type'])       ? $trace['type']        : null,
                                        isset($trace['function'])   ? $trace['function']    : null,
                                        $args
                                );
                            } else if ( isset($trace['info']) && $trace['info'] !== '' ) {
                                $info = ErrorHandler::syntaxHighlight( $trace['info'] );
                            } else if ( isset($trace['file']) && !isset($trace['info']) ) {
                                $contents = $this->getFileContents( $trace['file'] );

                                if ( $contents ) {
                                    $info = ErrorHandler::syntaxHighlight(
                                            trim( $contents[$trace['line']-1] )
                                    );
                                }
                            }

                            $trace['info'] = $info;

                            if ( isset($trace['file']) ) {
                                list( $type, $file ) = $this->getFolderType( $root, $trace['file'] );

                                $trace['file_type'] = $type;
                                $trace['is_native'] = false;
                            } else {
                                $file = '[Internal PHP]';

                                $trace['file_type'] = '';
                                $trace['is_native'] = true;
                            }

                            $trace['file'] = $file;

                            $fileLen = max( $fileLen, strlen($file) );

                            $stackTrace[$i] = $trace;
                        }
                    }

                    /*
                     * We are allowed to highlight just once, that's it.
                     */
                    $highlightI = -1;
                    foreach ( $stackTrace as $i => $trace ) {
                        if (
                                $trace['line'] === $errLine &&
                                $trace['file'] === $errFile
                        ) {
                            $highlightI = $i;
                            break;
                        }
                        }
 
                        foreach ( $stackTrace as $i => $trace ) {
                            if ( $trace ) {
                            // line
                                $line = str_pad($trace['line']     , $lineLen, ' ', STR_PAD_LEFT  );
    
                           // file
                                $file = $trace['file'];
                                $fileKlass = '';
                                if ( $trace['is_native'] ) {
                                    $fileKlass = 'file-internal-php';
                             } else {
                            $fileKlass = 'filename ' . ErrorHandler::folderTypeToCSS( $trace['file_type'] );
                            }
                            $file = $file . str_pad( '', $fileLen-strlen($file), ' ', STR_PAD_LEFT );
 
                                // info
                               $info = $trace['info'];
                                if ( $info ) {
                            $info = str_replace( "\n", '\n', $info );
                                $info = str_replace( "\r", '\r', $info );
                                } else {
                                    $info = '&nbsp;';
                                }
    
                            // line + file + info
                        $file = trim( $file );
    
                        $stackStr =
                                        "<td class='linenumber'>$line</td>" .
                                        "<td class='$fileKlass'>$file</td>".
                                        "<td class='lineinfo'>$info</td>"   ;
    
                                if ( $trace['is_native'] ) {
                                    $cssClass = 'is-native ';
                            } else {
                                $cssClass = '';
                             }
    
                                if ( $highlightI === $i ) {
                                $cssClass .= ' highlight';
                            } else if ( $highlightI > $i ) {
                                    $cssClass .= ' pre-highlight';
                        }
    
                                if (
                                        $i !== 0 &&
                                isset($trace['exception']) &&
                                        $trace['exception']
                                ) {
                                    $ex = $trace['exception'];
    
                                    $exHtml = '<tr class="error-stack-trace-exception"><td>' .
                                            'exception &quot;' .
                                        htmlspecialchars( $ex->getMessage() ) .
                                            '&quot;' .
                                        '</td></tr>';
                            } else {
                                    $exHtml = '';
                                }

                                $data = '';
                                if ( isset($trace['file-id']) ) {
                                $data = ' data-file-id="' . $trace['file-id'] . '"' .
                                            ' data-line="' . $line . '"' ;
                            }
    
                                $stackTrace[$i] = "$exHtml<tr class='error-stack-trace-line $cssClass' $data>$stackStr</tr>";
                        }
                        }
    
                        return '<table id="error-stack-trace">' . join( "", $stackTrace ) . '</table>';
                    } else {
                return null;
                    }
                }
    
            private function logError( $message, $file, $line, $ex=null) {
                if ( $ex ) {
                        $trace = $ex->getTraceAsString();
                $parts = explode( "\n", $trace);
                        $trace = "     " . join( "\n        ", $parts );
    
                    if ( ! ErrorHandler::isIIS() ) {
                            error_log( "$message \n           $file, $line \n$trace" );
                        }
            } else {
                        if ( ! ErrorHandler::isIIS() ) {
                         error_log( "$message \n       $file, $line" );
                }
                    }
                }
    
                /**
                * Given a class name, which can includea namespace,
                 * this will report that it is not found.
             *
                 * This will also reportit as an exception,
                 * so youwill get a full stack trace.
             */
                public function reportClassNotFound( $className ) {
                throw new \ErrorException( "Class '$className'not found", E_ERROR, 0, __FILE__, __LINE__ );
                }
    
            /**
                 * Given an exception,this will report it.
                 */
            public function reportException( $ex ) {
            $this->reportError(
                            $ex->getCode(),
                        $ex->getMessage(),
                            $ex->getLine(),
                          $ex->getFile(),
                    $ex
                    );
                }

                /**
                 * The entry point for handling an error.
                 *
                 * This is the lowest entry point for error reporting,
             * and for that reason it can either take just error info,
                 * or a combination of error and exception information.
                 *
             * Note that this will still log errors in the error log
                 * even when it's disabledwith ini. It just does nothing
             * more than that.
             */
            public function reportError( $code, $message, $errLine, $errFile, $ex=null ) {
                $this->discardBuffer();

                if (
                        $ex === null &&
                        $code === 1 &&
                        strpos($message, "Class ") === 0 &&
                        strpos($message, "not found") !== false &&
                        $this->classNotFoundException !== null
                ) {
                    $ex = $this->classNotFoundException;

                    $code       = $ex->getCode();
                    $message    = $ex->getMessage();
                    $errLine    = $ex->getLine();
                    $errFile    = $ex->getFile();
                    $stackTrace = $ex->getTrace();
                }

                $this->logError( $message, $errFile, $errLine, $ex );

                /**
                 * It runs if:
                 *  - it is globally enabled
                 *  - this error handler is enabled
                 *  - we believe it is a regular html request, or ajax
                 */
                global $_php_error_is_ini_enabled;
                if (
                        $_php_error_is_ini_enabled &&
                        $this->isOn() && (
                                $this->isAjax ||
                                !$this->htmlOnly ||
                                !ErrorHandler::isNonPHPRequest()
                        )
                ) {
                    $root = $this->applicationRoot;

                    list( $ex, $stackTrace, $code, $errFile, $errLine ) =
                            $this->getStackTrace( $ex, $code, $errFile, $errLine );

                    list( $message, $srcErrFile, $srcErrLine, $altInfo ) =
                            $this->improveErrorMessage(
                                    $ex,
                                    $code,
                                    $message,
                                    $errLine,
                                    $errFile,
                                    $root,
                                    $stackTrace
                            );

                    $errFile = $srcErrFile;
                    $errLine = $srcErrLine;

                    list( $fileLinesSets, $numFileLines ) = $this->generateFileLineSets( $srcErrFile, $srcErrLine, $stackTrace );

                    list( $type, $errFile ) = $this->getFolderType( $root, $errFile );
                    $errFileType = ErrorHandler::folderTypeToCSS( $type );

                    $stackTrace = $this->parseStackTrace( $code, $message, $errLine, $errFile, $stackTrace, $root, $altInfo );
                    $fileLines  = $this->readCodeFile( $srcErrFile, $srcErrLine );

                    // load the session, if ...
                    //  - there *is* a session cookie to load
                    //  - the session has not yet been started
                    // Do not start the session without he cookie, because there may be no session ever.
                    if ( isset($_COOKIE[session_name()]) && session_id() === '' ) {
                        session_start();
                    }

                    $arrays = array(
                        'post'    => ( isset($_POST)    ? $_POST    : array() ),
                        'get'     => ( isset($_GET)     ? $_GET     : array() ),
                        'session' => ( isset($_SESSION) ? $_SESSION : array() ),
                        'cookies' => ( isset($_COOKIE)  ? $_COOKIE  : array() ),
                    );

                    $arrays = array_merge($arrays, $this->customData);

                    $request  = ErrorHandler::getRequestHeaders();
                    $response = ErrorHandler::getResponseHeaders();

                    $dump = $this->generateDumpHTML(
                            $arrays,

                            $request,
                            $response,

                            $_SERVER
                    );
                    $this->displayError( $message, $srcErrLine, $errFile, $errFileType, $stackTrace, $fileLinesSets, $numFileLines, $dump );

                    // exit in order to end processing
                    $this->turnOff();
                    exit(0);
                }
            }

            private function getStackTrace( $ex, $code, $errFile, $errLine ) {
                $stackTrace = null;

                if ( $ex !== null ) {
                    $next = $ex;
                    $stackTrace = array();
                    $skipStacks = 0;

                    for (
                            $next = $ex;
                            $next !== null;
                            $next = $next->getPrevious()
                    ) {
                        $ex = $next;

                        $stack = $ex->getTrace();
                        $file  = $ex->getFile();
                        $line  = $ex->getLine();

                        if ( $stackTrace !== null && count($stackTrace) > 0 ) {
                            $stack = array_slice( $stack, 0, count($stack)-count($stackTrace) + 1 );
                        }

                        if ( count($stack) > 0 && (
                            !isset($stack[0]['file']) ||
                            !isset($stack[0]['line']) ||
                            $stack[0]['file'] !== $file ||
                            $stack[0]['line'] !== $line
                        ) ) {
                            array_unshift( $stack, array(
                                    'file' => $file,
                                    'line' => $line
                            ) );
                    }
    
                    $stackTrace = ( $stackTrace !== null )?
                                    array_merge( $stack, $stackTrace ) :
                                    $stack ;
    
                    if ( count($stackTrace) > 0 ) {
                            $stackTrace[0]['exception'] = $ex;
                       }
                    }
    
                    $message = $ex->getMessage();
                        $errFile = $ex->getFile();
                        $errLine =$ex->getLine();
    
                    $code = $ex->getCode();
    
                        if( method_exists($ex, 'getSeverity') ) {
                            $severity = $ex->getSeverity();
    
                            if ( $code === 0 && $severity !== 0 && $severity !== null ) {
                                $code = $severity;
                            }
                        }
                    }
    
                    return array( $ex, $stackTrace, $code, $errFile, $errLine );
                }
    
                private function generateDumpHTML( $arrays, $request, $response, $server ) {
                    $arrToHtml = function( $name, $array, $css='' ) {
                    $max = 0;
    
                    foreach ( $array as $e => $v ) {
                        $max = max( $max, strlen( $e ) );
                        }
    
                        $snippet = "<h2 class='error_dump_header'>$name</h2>";
    
                    foreach ( $array as $e => $v ) {
                            $e = str_pad( $e, $max, ' ', STR_PAD_RIGHT );
    
                            $e = htmlentities( $e );
                            $v = ErrorHandler::identifyTypeHTML( $v, 3 );
    
                            $snippet.= "<div class='error_dump_key'>$e</div><div class='error_dump_mapping'>=&gt;</div><div class='error_dump_value'>$v</div>";
                    }
    
                       return "<div class='error_dump $css'>$snippet</div>";
                    };
    
                $html = '';
            foreach ( $arrays as $key => $value ) {
                        if ( isset($value) && $value ) {
                          $html .= $arrToHtml( $key, $value );
                        } else {
                            unset($arrays[$key]);
                        }
                    }
    
                return "<div class='error-dumps'>" .
                                $html .
                            $arrToHtml( 'request', $request, 'dump_request' ) .
                                $arrToHtml( 'response', $response, 'dump_response' ) .
                                $arrToHtml( 'server', $server, 'dump_server' ) .
                         "</div>";
                }
    
                private function generateFileLineSets( $srcErrFile, $srcErrLine, &$stackTrace) {
                    $fileLineID = 1;
                    $srcErrID = "file-line-$fileLineID";
                    $fileLineID++;
    
    
                    $lines = $this->getFileContents( $srcErrFile );
                    $minSize = count( $lines );
    
                    $srcFileSet = new FileLinesSet( $srcErrFile, $srcErrID, $lines );
   
                    $seenFiles = array( $srcErrFile => $srcFileSet );
    
                    if ( $stackTrace ) {
                    foreach ( $stackTrace as $i => &$trace ) {
                            if ( $trace && isset($trace['file']) && isset($trace['line']) ) {
                            $file= $trace['file'];
                                $line = $trace['line'];
    
                            if ( isset($seenFiles[$file]) ) {
                                    $fileSet = $seenFiles[$file];
                            } else {
                                   $traceFileID = "file-line-$fileLineID";
    
                            $lines = $this->getFileContents( $file );
                                    $minSize = max( $minSize, count($lines));
                                    $fileSet = new FileLinesSet( $file, $traceFileID, $lines );
    
                                    $seenFiles[ $file ] = $fileSet;
    
                                $fileLineID++;
                            }
    
                                $trace['file-id'] = $fileSet->getHTMLID();
                        }
                        }
            }
    
                    return array( array_values($seenFiles), $minSize );
                }
    
                /*
                 *Even if disabled, we still act like reporting is on,
                 * if it's turned on.
                 *
                 * We just don't do anything.
                */
                private function setEnabled( $isOn ) {
                    $wasOn = $this->isOn;
                    $this->isOn = $isOn;
    
                    global $_php_error_is_ini_enabled;
                    if ( $_php_error_is_ini_enabled ) {
                        /*
                         * Only turn off, if we're moving from on to off.
                     *
                     * This is so if it's turned off without turning on,
                     * we don't change anything.
                     */
                    if ( !$isOn ) {
                        if ( $wasOn ) {
                            $this->runDisableErrors();
                        }
                    /*
                     * Always turn it on, even if already on.
                     *
                     * This is incase it was messed up in some way
                     * by the user.
                     */
                    } else if ( $isOn ) {
                        $this->runEnableErrors();
                    }
                }
            }

            private function runDisableErrors() {
                global $_php_error_is_ini_enabled;

                if ( $_php_error_is_ini_enabled ) {
                    error_reporting( $this->defaultErrorReportingOff );

                    @ini_restore( 'html_errors' );

                    if ( ErrorHandler::isIIS() ) {
                        @ini_restore( 'log_errors' );
                    }
                }
            }

            /*
             * Now the actual hooking into PHP's error reporting.
             *
             * We enable _ALL_ errors, and make them all exceptions.
             * We also need to hook into the shutdown function so
             * we can catch fatal and compile time errors.
             */
            private function runEnableErrors() {
                global $_php_error_is_ini_enabled;

                if ( $_php_error_is_ini_enabled ) {
                    $catchSurpressedErrors = &$this->catchSurpressedErrors;
                    $self = $this;

                    // all errors \o/ !
                    error_reporting( $this->defaultErrorReportingOn );
                    @ini_set( 'html_errors', false );

                    if ( ErrorHandler::isIIS() ) {
                        @ini_set( 'log_errors', false );
                    }

                    set_error_handler(
                            function( $code, $message, $file, $line, $context ) use ( $self, &$catchSurpressedErrors ) {
                                /*
                                 * DO NOT! log the error.
                                 *
                                 * Either it's thrown as an exception, and so logged by the exception handler,
                                 * or we return false, and it's logged by PHP.
                                 *
                                 * Also DO NOT! throw an exception, instead report it.
                                 * This is because if an operation raises both a user AND
                                 * fatal error (such as require), then the exception is
                                 * silently ignored.
                                 */
                                if ( $self->isOn() ) {
                                    /*
                                     * When using an @, the error reporting drops to 0.
                                     */
                                    if ( error_reporting() !== 0 || $catchSurpressedErrors ) {
                                        $ex = new \ErrorException( $message, $code, 0, $file, $line );

                                        $self->reportException( $ex );
                                    }
                                } else {
                                    return false;
                                }
                            },
                            $this->defaultErrorReportingOn
                    );

                    set_exception_handler( function($ex) use ( $self ) {
                        if ( $self->isOn() ) {
                            $self->reportException( $ex );
                        } else {
                            return false;
                        }
                    });

                    if ( ! $self->isShutdownRegistered ) {
                        if ( $self->catchClassNotFound ) {
                            $classException = &$self->classNotFoundException;
                            $autoloaderFuns = ErrorHandler::$SAFE_AUTOLOADER_FUNCTIONS;

                            /*
                             * When this is called, the key point is that we don't error!
                             *
                             * Instead we record that an error has occurred,
                             * if we believe one has, and then let PHP error as normal.
                             * The stack trace we record is then used later.
                             *
                             * This is done for two reasons:
                             *  - functions like 'class_exists' will run the autoloader, and we shouldn't error on them
                             *  - on PHP 5.3.0, the class loader registered functions does *not* return closure objects, so we can't do anything clever.
                             *
                             * So we watch, but don't touch.
                             */
                            spl_autoload_register( function($className) use ( $self, &$classException, &$autoloaderFuns ) {
                                if ( $self->isOn() ) {
                                    $classException = null;

                                    // search the stack first, to check if we are running from 'class_exists' before we error
                                    if ( defined('DEBUG_BACKTRACE_IGNORE_ARGS') ) {
                                        $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
                                    } else {
                                        $trace = debug_backtrace();
                                    }
                                    $error = true;

                                    foreach ( $trace as $row ) {
                                        if ( isset($row['function']) ) {
                                            $function = $row['function'];

                                            // they are just checking, so don't error
                                            if ( in_array($function, $autoloaderFuns, true) ) {
                                                $error = false;
                                                break;
                                            // not us, and not the autoloader, so error!
                                            } else if (
                                                   $function !== '__autoload' &&
                                                    $function !== 'spl_autoload_call' &&
                                                    strpos($function, 'php_error\\') === false
                                            ) {
                                                break;
                                        }
                                            }
                                }
    
                                        if ( $error) {
                                            $classException = new \ErrorException( "Class '$className' not found", E_ERROR, 0, __FILE__, __LINE__ );
                                    }
                                }
                                } );
                        }
    
                            $self->isShutdownRegistered = true;
                        }
                    }
              }
    
                private function displayJSInjection() {
                    ?><script data-php_error="magic JS, just ignore this!">
                    "use strict";
    
                        (function( window ) {
                        if ( window.XMLHttpRequest ) {
                                /**
                             * A method wrapping helper function.
                             *
                                 * Wraps the method given, from the old prototype to the new
                             * XMLHttpRequest prototype.
                                 *
                         * This only happens if the old one actually has that prototype.
                             * If the browser doesn't support a prototype, then it doesn't
                         * get wrapped.
                                 */
                            var wrapMethod =function( XMLHttpRequest, old, prop ) {
                            if ( old.prototype[prop] ) {
                                    var behaviours = ( arguments.length > 3 ) ?
                                                Array.prototype.slice.call( arguments, 3 ) :
                                        null ;
    
                                       XMLHttpRequest.prototype[prop] = function() {
                                        if ( behaviours !== null ) {
                                                for( var i = 0; i < behaviours.length; i++ ) {
                                                behaviours[i].call( this, arguments, prop );
                                        }
                                        }
    
                                        return this.__.inner[prop].apply( this.__.inner, arguments );
                                    };
                                    }
                            }
    
                              var postMethod = function( XMLHttpRequest, prop ) {
                                 if ( XMLHttpRequest.prototype[prop] ) {
                                    var behaviours = Array.prototype.slice.call(arguments, 2 );
    
                                        var previous = XMLHttpRequest.prototype[prop];
                                        XMLHttpRequest.prototype[prop]= function() {
                                            var r = previous.apply( this, arguments );
    
                                            for ( var i = 0; i < behaviours.length; i++ ) {
                                                behaviours[i].call( this, arguments, prop );
                                    }
    
                                            return r;
                                    };
                                }
                               }
    
                                /*
                            * Certain properties will error when read,
                                 * and which ones do vary from browser to browser.
                             *
                                 * I've found both Chrome and Firefox will error
                             * on _different_ properties.
                             *
                                 * So every read needs to be wrapped in a try/catch,
                                 * and just hope it doesn't error.
                                 */
                                var copyProperties = function( src, dest, props ) {
                                for ( var i = 0; i < props.length; i++ ) {
                                    try {
                                        var prop = props[i];
                                        dest[prop] = src[prop];
                                    } catch( ex ) { }
                                }
                            };

                            var copyResponseProperties = function( src, dest ) {
                                copyProperties( src, dest, [
                                        'response',
                                        'responseText',
                                        'responseXML'
                                ]);
                            }

                            var copyRequestProperties = function( src, dest, includeReadOnly, skipResponse ) {
                                copyProperties( src, dest, [
                                        'readyState',
                                        'timeout',
                                        'upload',
                                        'withCredentials',
                                        'responseType',

                                        'mozBackgroundRequest',
                                        'mozArrayBuffer',
                                        'multipart'
                                ]);

                                if ( includeReadOnly ) {
                                    copyProperties( src, dest, [
                                            'status',
                                            'statusText',
                                            'channel'
                                    ]);

                                    if ( ! skipResponse ) {
                                        copyResponseProperties( src, dest );
                                    }
                                }

                                return dest;
                            }

                            var runFail = function( ev ) {
                                var self = this;
                                var xmlHttpRequest = this.__.inner;

                                var iframe = document.createElement('iframe');
                                iframe.setAttribute('width', '100%');
                                iframe.setAttribute('height', '100%');
                                iframe.setAttribute('src', 'about:blank');

                                iframe.style.transition =
                                iframe.style.OTransition =
                                iframe.style.MsTransition =
                                iframe.style.MozTransition =
                                iframe.style.WebkitTransition = 'opacity 200ms linear';

                                iframe.style.background = 'transparent';
                                iframe.style.opacity = 0;
                                iframe.style.zIndex = 100001;
                                iframe.style.top = 0;
                                iframe.style.right = 0;
                                iframe.style.left = 0;
                                iframe.style.bottom = 0;
                                iframe.style.position = 'fixed';

                                var response = xmlHttpRequest.responseText;

                                iframe.onload = function() {
                                    var iDoc = iframe.contentWindow || iframe.contentDocument;
                                    if ( iDoc.document) {
                                        iDoc = iDoc.document;
                                    }

                                    var iBody = iDoc.getElementsByTagName("body")[0];
                                    iBody.innerHTML = response;
                                    var iHead = iDoc.getElementsByTagName("head")[0];

                                    // re-run the script tags
                                    var scripts = iDoc.getElementsByTagName('script');
                                    for ( var i = 0; i < scripts.length; i++ ) {
                                        var script = scripts[i];
                                        var parent = script.parentNode;

                                        if ( parent ) {
                                            parent.removeChild( script );

                                            var newScript = iDoc.createElement('script');
                                            newScript.innerHTML = script.innerHTML;

                                            iHead.appendChild( newScript );
                                        }
                                    }

                                    var closed = false;
                                    var closeIFrame = function() {
                                        if ( ! closed ) {
                                            closed = true;

                                            iframe.style.opacity = 0;

                                            setTimeout( function() {
                                                iframe.parentNode.removeChild( iframe );
                                            }, 220 );
                                        }
                                    }

                                    /*
                                     * Retry Handler.
                                     *
                                     * Clear this, make a new (real) XMLHttpRequest,
                                     * and then re-run everything.
                                     */
                                    var retry = iDoc.getElementById('ajax-retry');
                                    if ( retry ) {
                                        var retryFun = function() {
                                            var methodCalls = self.__.methodCalls;

                                            initializeXMLHttpRequest.call( self );
                                            for ( var i = 0; i < methodCalls.length; i++ ) {
                                                var method = methodCalls[i];
                                                self[method.method].apply( self, method.args );
                                            }

                                            closeIFrame();

                                            return false;
                                        };
                                        retry.onclick = retryFun;

                                        iframe.__php_error_retry = retryFun;

                                        /*
                                         * The close handler.
                                         *
                                         * When closed, the response is cleared,
                                         * and then the request finishes with null info.
                                         */
                                        iDoc.getElementById('ajax-close').onclick = function() {
                                            copyRequestProperties( self.__.inner, self, true );
    
                                            // clear the response
                                            self.response       = '';
                                            self.responseText   = '';
                                            self.responseXML= null;
    
                                                if ( self.onreadystatechange ) {
                                                self.onreadystatechange( ev );
                                        }

                                                closeIFrame();
                                            return false;
                                            };
    
                                        var html = iDoc.getElementsByTagName('html')[0];
                                        html.setAttribute( 'class', 'ajax' );
  
                                            setTimeout( function() {
                                                iframe.style.opacity = 1;
                                        }, 1 );
                                    }
                                    }
    
                                    /*
                                 * Placed inside atimeout, incase the document doesn't exist yet.
                                 *
                                     * Can happen if the page ajax's straight away.
                                 */
                                    setTimeout( function() {
                                        var body = document.getElementsByTagName('body')[0];
                                    body.appendChild( iframe );
                                }, 1 );
                               }
    
                                var old = window.XMLHttpRequest;
 
                                /**
                             * The middle man http request object.
                                 *
                                 * Acts just like a normal one, but will show errors if they
                                 * occur instead of running the result.
                             */
                                var XMLHttpRequest = function() {
                                 initializeXMLHttpRequest.call( this );
                                }
    
                                var initializeXMLHttpRequest = function() {
                                    var self = this,
                                    inner = new old();
    
                               /**
                                     * With a buggy XMLHttpRequest, it's possible to accidentally run the error handler
                                     * multiple times.
                                 *
                                     * This is a flag to only do it once, to keep the code more defensive.
                                     */
                                    var errorOnce   = true,
                                        isAjaxError = false;
    
                               var stateResults = [];
    
                                inner.onreadystatechange =function( ev ) {
                                    copyRequestProperties( inner, self, true, true );
    
                                var state = inner.readyState;
    
                                        /*
                                         * Check headers for error.
                                     */
                                    if ( ! isAjaxError && state >= 2 ) {
                                        /*
                                         * It's null in some browsers, and an empty string in others.
                                         */
                                        var header = inner.getResponseHeader( '<?php echo ErrorHandler::PHP_ERROR_MAGIC_HEADER_KEY ?>' );

                                        if ( header !== null && header !== '' ) {
                                            self.__.isAjaxError = true;
                                            isAjaxError = true;
                                        }
                                    }

                                    if ( ! isAjaxError && state >= 2 ) {
                                        copyResponseProperties( inner, self );
                                    }

                                    /*
                                     * Success ! \o/
                                     *
                                     * Pass any state change on to the parent caller,
                                     * unless we hit an ajaxy error.
                                     */
                                    if ( !isAjaxError && self.onreadystatechange ) {
                                        /*
                                         * One of three things happens:
                                         *  = cache the requests until we know there is no error (state 4)
                                         *  = we know there is no error, and so we run our cache
                                         *  = cache is done, but we've been called again, so just pass it on
                                         */
                                        if ( state < 4 ) {
                                            stateResults.push( copyRequestProperties(self, {}, true) );
                                        } else {
                                            if ( stateResults !== null ) {
                                                var currentState = copyRequestProperties( self, {}, true );

                                                for ( var i = 0; i < stateResults.length; i++ ) {
                                                    var store = stateResults[i];
                                                    copyRequestProperties( store, self, true );

                                                    // must check a second time here,
                                                    // in case it gets changed within an onreadystatechange
                                                    if ( self.onreadystatechange ) {
                                                        self.onreadystatechange( ev );
                                                    }
                                                }

                                                copyRequestProperties( currentState, self, true );
                                                stateResults = null;
                                            }

                                            if ( self.onreadystatechange ) {
                                                self.onreadystatechange( ev );
                                            }
                                        }
                                    }

                                    /*
                                     * Fail : (
                                     */
                                    if (
                                            isAjaxError &&
                                            state === 4 &&
                                            errorOnce
                                    ) {
                                        errorOnce = false;
                                        if ( window.console && window.console.log ) {
                                            window.console.log( 'Ajax Error Calling: ' + self.__.url );
                                        }

                                        runFail.call( self, ev );
                                    }
                                };

                                copyRequestProperties( inner, this, true );

                                /*
                                 * Private fields are stored underneath the unhappy face,
                                 * to localize them.
                                 *
                                 * Access becomes:
                                 *  this.__.fieldName
                                 */
                                this.__ = {
                                        methodCalls: [],
                                        inner: inner,
                                        isAjaxError: false,
                                        isSynchronous: false,
                                        url: ''
                                };
                            }

                            /*
                             * We build the methods for the fake XMLHttpRequest.
                             */

                            var copyIn = function() {
                                copyRequestProperties( this, this.__.inner );
                            }
                            var copyOut = function() {
                                copyRequestProperties( this.__.inner, this, true, this.__.isSynchronous && this.__.isAjaxError );
                            }
                            var addHeader = function() {
                                this.__.inner.setRequestHeader( 'HTTP_X_REQUESTED_WITH', 'XMLHttpRequest' );
                            }
                            var isSynchronous = function( args ) {
                                this.__.isSynchronous = ( args[2] === false );
                            }
                            var saveRequest = function( args, method ) {
                                this.__.methodCalls.push({
                                    method: method,
                                    args: args
                                });
                            }
                            var grabOpen = function( args, method ) {
                                this.__.url = args[1];
                            }

                            wrapMethod( XMLHttpRequest, old, 'open'        , saveRequest, copyIn, isSynchronous, grabOpen );
                            wrapMethod( XMLHttpRequest, old, 'abort'       , saveRequest, copyIn );
                            wrapMethod( XMLHttpRequest, old, 'send'        , saveRequest, copyIn, addHeader );
                            wrapMethod( XMLHttpRequest, old, 'sendAsBinary', saveRequest, copyIn, addHeader );

                            postMethod( XMLHttpRequest,      'send'        , copyOut );
                            postMethod( XMLHttpRequest,      'sendAsBinary', copyOut );

                            wrapMethod( XMLHttpRequest, old, 'getAllResponseHeaders', saveRequest );
                            wrapMethod( XMLHttpRequest, old, 'getResponseHeader'    , saveRequest );
                            wrapMethod( XMLHttpRequest, old, 'setRequestHeader'     , saveRequest );
                            wrapMethod( XMLHttpRequest, old, 'overrideMimeType'     , saveRequest );

                            window.XMLHttpRequest = XMLHttpRequest;
                        }
                    })( window );
                </script><?php
            }

            /**
             * The actual display logic.
             * This outputs the error details in HTML.
             */
            private function displayError( $message, $errLine, $errFile, $errFileType, $stackTrace, &$fileLinesSets, $numFileLines, $dumpInfo, $code ) {
                $applicationRoot   = $this->applicationRoot;
                $serverName        = $this->serverName;
                $backgroundText    = $this->backgroundText;
                $displayLineNumber = $this->displayLineNumber;
                    $saveUrl           = $this->saveUrl;
                    $isSavingEnabled   = $this->isSavingEnabled;
                    $showErrorCode	   = $this->showErrorCode;
                    $codeDescription   = (!empty(ErrorHandler::$PHP_ERROR_MAPPINGS[$code]) ? ErrorHandler::$PHP_ERROR_MAPPINGS[$code] : '');
    
                    /*
                     * When a query string is not provided,
                 * in some versions it's a blank string,
                     * whilst in others it's not set at all.
                     */
                    if ( isset($_SERVER['QUERY_STRING']) ) {
                        $requestUrl = str_replace( $_SERVER['QUERY_STRING'], '', $_SERVER['REQUEST_URI'] );
                        $requestUrlLen = strlen( $requestUrl );
    
                        // remove the '?' if it's there (I suspect it isn't always, but don't take my word for it!)
                    if ( $requestUrlLen > 0 && substr($requestUrl, $requestUrlLen-1) === '?' ) {
                          $requestUrl = substr( $requestUrl, 0, $requestUrlLen-1 );
                        }
                    } else {
                    $requestUrl = $_SERVER['REQUEST_URI'];
                    }
    
                header_remove('Content-Transfer-Encoding');
                    $this->displayHTML(
                    // pre, in the head
                            function() use( $message, $errFile, $errLine ) {
                                    echo "<!--\n" .
                                    "$message\n" .
                                        "$errFile, $errLine\n" .
                                    "-->";
                            },
    
                        // the content
                            function() use (
                                    $requestUrl,
                                    $backgroundText, $serverName, $applicationRoot,
                                $message, $errLine, $errFile, $errFileType, $stackTrace,
                                &$fileLinesSets, $numFileLines,
                                    $displayLineNumber,
                                    $dumpInfo,
                            $isSavingEnabled,
                                    $showErrorCode,
                                $code,
    	                        $codeDescription
                            ) {
                            if ( $backgroundText ) { ?>
                             <div id="error-wrap">
                                        <div id="error-back"><?php echo $backgroundText ?></div>
                                </div>
                                <?php } ?>
    
                                <h2 id="error-file-root"><?php echo $serverName ?> | <?php echo $applicationRoot ?></h2>
                        <h2 id="ajax-info">
                                    <span id="ajax-tab" class="ajax-button">AJAX PAUSED</span>
    
                                <span class="ajax-url"><?php echo $serverName ?><?php echo $requestUrl ?></span>
                                <span class="ajax-buttons">
                                    <a href="#" id="ajax-close" class="ajax-button">X</a>
                                    <a href="#" id="ajax-retry" class="ajax-button">RETRY</a>
                                </span>
                            </h2>
                            <h1 id="error-title"><?php echo $message ?></h1>
                            <?php echo ($showErrorCode ? " <h3 id='error-code'>".$codeDescription." (".$code.")</h3>" : ""); ?>
                            <div class="error-file-top <?php echo ($fileLinesSets ? 'has_code' : '') ?>">
                                <h2 id="error-file"><span id="error-linenumber"><?php echo $errLine ?></span> <span id="error-filename" class="<?php echo $errFileType ?>"><?php echo $errFile ?></span></h2>
                                <?php if ( $isSavingEnabled ) { ?>
                                    <a href="#" class="error-file-save">save changes</a>
                                <?php } ?>
                            </div>
                            <?php

                            if ( $fileLinesSets ) {
                                ?>
                                    <div id="error-editor" class="<?php echo ($displayLineNumber ? '' : 'no-line-nums') ?>">
                                        <noscript>
                                            <div id="noscript-editor">enable JavaScript to view source code</div>
                                        </noscript>
                                        <div id="error-editor-ace"></div>
                                    </div>
                                <?php

                                foreach ( $fileLinesSets as $i => $fileLinesSet ) {
                                    $id            = $fileLinesSet->getHTMLID();
                                    $fileLines     = $fileLinesSet->getLines();

                                    ?><div
                                            data-file-id="<?php echo $fileLinesSet->getHTMLID() ?>"
                                            data-file-src="<?php echo $fileLinesSet->getSrc() ?>"
                                            class="error-editor-file"
                                    ><?php echo htmlentities( $fileLinesSet->getContent() ); ?></div><?php
                                }
                            }

                            if ( $stackTrace !== null ) {
                                echo $stackTrace;
                            }

                            if ( $dumpInfo !== null ) {
                                echo $dumpInfo;
                            }
                        },

                        /**
                         * Adds:
                         *  = mouse movement for switching the code snippet in real time
                         */
                        function() use ( $saveUrl ) {
                            ?><script>
                                "use strict";

                                $(document).ready( function() {
                                    $('#ajax-close', '#ajax-retry').click( function(ev) {
                                        ev.preventDefault();
                                    });

                                    var $editor = $('#error-editor-ace');
                                    if ( $editor.size() > 0 ) {
                                        var editor = null;
                                        var selectFile;
                                        var lines = {};
                                        var lastFileID = null;

                                        var files = $('.error-editor-file');
                                        var EditSession = ace.require('ace/edit_session').EditSession;
                                        var php = ace.require('ace/mode/php');

                                        var changedFiles = {},
                                            fileSessions = {};
                                        var currentFile = null;

                                        var editor = ace.edit( $editor.get(0) );

                                        editor.on('change', function() {
                                            if ( currentFile !== null ) {
                                                changedFiles[currentFile] = true;
                                            }
                                        } );

                                        var selectFile = function( link, line ) {
                                            var fileID = link.attr('data-file-id');
                                            if ( line === undefined ) {
                                                line = link.attr('data-line');
                                            }

                                            setTimeout( function() {
                                                var file;

                                                for ( var i = 0; i < files.size(); i++ ) {
                                                    var f = files.get(i);

                                                    if ( f.getAttribute('data-file-id') === fileID ) {
                                                        file = f;
                                                    }
                                                }

                                                if ( file ) {
                                                    currentFile = file.getAttribute('data-file-src');
                                                    var session;

                                                    if ( fileSessions.hasOwnProperty(currentFile) ) {
                                                        session = fileSessions[ currentFile ];
                                                    } else {
                                                        session = fileSessions[ currentFile ] =
                                                                new EditSession( file.textContent );
                                                        session.setMode( 'ace/mode/php' );
                                                    }

                                                    editor.setSession( session );

                                                    editor.gotoLine( line );

                                                    editor.focus();
                                                }
                                            }, 0 );
                                        }

                                        var FADE_SPEED = 150,
                                            lines = $('#error-files .error-file-lines'),
                                            currentID = '#' + lines.filter( '.show' ).attr( 'id' );

                                        var filename   = $('#error-filename'),
                                            linenumber = $('#error-linenumber');

                                        $( '.error-stack-trace-line' ).
                                                mouseover( function() {
                                                    var $this = $(this);

                                                    if ( ! $this.hasClass('select-highlight') ) {
                                                        $this.addClass( 'select-highlight' );
                                                    }
                                                }).
                                                mouseout( function(ev) {
                                                    var $this = $(this);

                                                    $this.removeClass( 'select-highlight' );
                                                }).
                                                click( function() {
                                                    var $this = $(this);

                                                    if ( ! $this.hasClass('highlight') && !$this.hasClass('is-native') ) {
                                                        $( '.error-stack-trace-line.highlight' ).removeClass( 'highlight' );
                                                        $this.addClass( 'highlight' );

                                                        selectFile( $this, $this.attr('data-line') );
                                                    }
                                                });

                                        $('#error-stack-trace').mouseleave( function() {
                                            lines.filter('.show').removeClass( 'show' );
                                            lines.filter( currentID ).addClass( 'show' );
                                        });

                                        selectFile( $('.error-stack-trace-line.highlight') );

                                        $('.error-file-save').click( function(ev) {
                                            ev.preventDefault();
                                            ev.stopPropagation();

                                            var files = {},
                                                hasChanges = false;

                                            for ( var k in changedFiles ) {
                                                if ( fileSessions.hasOwnProperty(k) && changedFiles.hasOwnProperty(k) && changedFiles[k] ) {
                                                    files[k] = fileSessions[k].getValue();
                                                    hasChanges = true;
                                                }
                                            }

                                            var retryFun = null;
                                            if ( window.top !== window ) {
                                                var arrFrames = parent.document.getElementsByTagName("IFRAME");

                                                for (var i = 0; i < arrFrames.length; i++) {
                                                    if (arrFrames[i].contentWindow === window) {
                                                        retryFun = arrFrames[i].__php_error_retry;
                                                    }
                                                }
                                            }

                                            if ( retryFun === null ) {
                                                retryFun = function() {
                                                    document.location.reload( true );
                                                }
                                            }

                                            if ( ! hasChanges ) {
                                                retryFun();
                                            } else {
                                                $.ajax({
                                                        type: "POST",
                                                        url: "<?php echo $saveUrl ?>",
                                                        dataType: "json",

                                                        data: {
                                                            "<?php echo ErrorHandler::POST_FILE_LOCATION ?>": files
                                                        },

                                                        success: function(res, status, xhr) {
                                                            retryFun();
                                                        },

                                                        beforeSend: function(xhr) {
                                                            xhr.setRequestHeader( "<?php echo ErrorHandler::HEADER_SAVE_FILE ?>", 'true' );
                                                        }
                                                });
                                            }
                                        } );
                                    }
                                } );
                            </script><?php
                        }
                );
            }

            /**
             * A generic function for clearing the buffer, and displaying error output.
             *
             * A function needs to be given, and then this is run at the correct time.
             * There are two ways this can be used:
             *
             *  displayHTML( $head, $body )
             *
             * Here 'head' is run straight after the doctype, whilst 'body' is run as
             * the body for the content. The other way is:
             *
             *  displayHTML( $body )
             *
             * Here there is only content.
             */
            function displayHTML( Closure $head, $body=null, $javascript=null ) {
                if ( func_num_args() === 2 ) {
                    $body = $head;
                    $head = null;
                }

                // clean out anything displayed already
                try {
                    while(ob_get_level() > 0) {
                        ob_end_clean();
                    }
                } catch ( Exception $ex ) { /* do nothing */ }

                if (!$this->htmlOnly && ErrorHandler::isNonPHPRequest()) {
                    @header( "Content-Type: text/html" );
                }
                @header( ErrorHandler::PHP_ERROR_MAGIC_HEADER_KEY . ': ' . ErrorHandler::PHP_ERROR_MAGIC_HEADER_VALUE );

                echo '<!DOCTYPE html>';

                if ( $head !== null ) {
                    $head();
                }

                echo "<link href='http://fonts.googleapis.com/css?family=Droid+Sans+Mono' rel='stylesheet' type='text/css'>";

                ?><style>
                    <?php
                    /* STYLE.CSS */
                    if (defined('PHPERROR_STYLE_CSS'))
                    {
                        $js_blob = @file_get_contents(PHPERROR_STYLE_CSS);
                    }
                    else
                    {
                        $js_blob = @file_get_contents(dirname(__FILE__) . '/php_error_style.css');
                    }
                    ?>
                </style>

                <div class="background"><?php
                    $body();
                ?></div>

                <?php

                /*
                 * ace.ajax
                 */
                ?>
                <script>
                <?php
                /* ACE editor */
                if (defined('PHPERROR_ACE_EDITOR_INCLUDEPATH'))
                {
                    $js_blob = @file_get_contents(PHPERROR_ACE_EDITOR_INCLUDEPATH);
                }
                else
                {
                    $js_blob = @file_get_contents(dirname(__FILE__) . '/php_error_ace.js');
                }
                echo $js_blob;
                ?>
                </script>

                <script>
                /* jQuery library */
                <?php
                if (defined('PHPERROR_JQUERY_INCLUDEPATH'))
                {
                    $js_blob = @file_get_contents(PHPERROR_JQUERY_INCLUDEPATH);
                }
                else
                {
                    $js_blob = @file_get_contents(dirname(__FILE__) . '/php_error_jquery.js');
                }
                echo $js_blob;
                ?>
                </script>

                <?php

                if ( $javascript ) {
                    $javascript();
                }
            }
        }

        /**
         * Code is outputted multiple times, for each file involved.
         * This allows us to wrap up a single set of code.
         */
        class FileLinesSet
        {
            private $src;
            private $id;
            private $lines;

            public function __construct( $src, $id, array $lines ) {
                $this->src   = $src;
                $this->id    = $id;
                $this->lines = $lines;
            }

            public function getSrc() {
                return $this->src;
            }

            public function getHTMLID() {
                return $this->id;
            }

            public function getLines() {
                return $this->lines;
            }

            public function getContent() {
                return implode( "\n", $this->lines );
            }
        }


        if (
                $_php_error_is_ini_enabled &&
                $_php_error_global_handler === null &&
                @get_cfg_var('php_error.autorun')
        ) {
            reportErrors();
        }
    }
\php_error\reportErrors();
