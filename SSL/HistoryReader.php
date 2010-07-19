<?php

/**
 *  @author      Ben XO (me@ben-xo.com)
 *  @copyright   Copyright (c) 2010 Ben XO
 *  @license     MIT License (http://www.opensource.org/licenses/mit-license.html)
 *  
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is
 *  furnished to do so, subject to the following conditions:
 *  
 *  The above copyright notice and this permission notice shall be included in
 *  all copies or substantial portions of the Software.
 *  
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 */

class HistoryReader
{
    // command line switches
    protected $dump_and_exit = false;
    protected $wait_for_file = true;
    protected $help = false;
    protected $replay = false;
    protected $csv = false;
    protected $auth_lastfm = false;
    protected $lastfm_username;
    protected $log_file = '';
    protected $verbosity = L::INFO;
    
    protected $override_verbosity = array();
    
    protected $sleep = 2;
    
    protected $appname;
    protected $filename;
    protected $historydir;
    
    protected $growl_config;
    protected $lastfm_config;
    
    /**
     * @var Logger
     */
    protected $logger;
    
    public function setGrowlConfig(array $growlConfig)
    { 
        $this->growl_config = $growlConfig;
    }
    
    public function setLastfmConfig(array $lastfmConfig)
    {
        $this->lastfm_config = $lastfmConfig;
    }
    
    public function setVerbosityOverride(array $override)
    {
        $this->override_verbosity = $override;
    }
    
    public function main($argc, array $argv)
    {
        date_default_timezone_set('UTC');
        
        try
        {
            $this->parseOptions($argv);
            
            $this->setupLogging();
                        
            $appname = $this->appname;
            
            if($this->help)
            {
                $this->usage($appname, $argv);
                return;
            }
            
            if($this->auth_lastfm)
            {
                $this->authLastfm();
                return;
            }
            
            if(isset($this->lastfm_username))
            {
                $sk_file = 'lastfm-' . $this->lastfm_username . '.txt';
                while(!file_exists($sk_file))
                {
                    echo "Last.fm username supplied, but no Session Key saved. Authorizing...\n";
                    $this->authLastfm();
                }
                
                $this->lastfm_config['api_sk'] = trim(file_get_contents($sk_file));
            }
            
            $filename = $this->filename;
            
            if(empty($filename))
            {
                // guess history file (always go for the most recently modified)
                $this->historydir = $this->getDefaultHistoryDir();
                
                if($this->wait_for_file)
                {
                    echo "Waiting for new session file...\n";
                    // find the most recent file, then wait for a new one to be created and use that.
                    $first_filename = $this->getMostRecentFile($this->historydir, '.session');
                    $second_filename = $first_filename;
                    while($second_filename == $first_filename)
                    {
                        sleep($this->sleep);
                        $second_filename = $this->getMostRecentFile($this->historydir, '.session');
                    }
                    $filename = $second_filename;
                }
                else
                {
                    $filename = $this->getMostRecentFile($this->historydir, '.session');                
                }
                
                echo "Using file $filename ...\n";
            }
                            
            if(!file_exists($filename))
                throw new InvalidArgumentException("No such file $filename.");
                
            if(!is_readable($filename))
                throw new InvalidArgumentException("File $filename not readable.");
                
                
            if($this->dump_and_exit)
            {
                $monitor = new SSLHistoryFileDiffMonitor($filename);
                $monitor->dump();
                return;
            }

            // start monitoring.
            $this->monitor($filename);            
        }
        catch(Exception $e)
        {   
            echo $e->getMessage() . "\n";
            if($this->verbosity > L::INFO)
            {
                echo $e->getTraceAsString() . "\n";
            }  
            echo "Try {$appname} --help\n";
        }
    }
    
    public function usage($appname, array $argv)
    {
        echo "Usage: {$appname} [OPTIONS] [session file]\n";
        echo "Session file is optional. If omitted, the most recent history file from {$this->historydir} will be used automatically\n";
        echo "    -h or --help:              This message.\n";
        echo "    -i or --immediate:         Do not wait for the next history file to be created before monitoring. (Use if you started {$appname} mid way through a session)\n";
        echo "\n";
        echo "Last.fm options:\n";
        echo "    -L or --lastfm <username>: Scrobble / send 'Now Playing' to Last.fm for user <username>. (Will ask you to authorize if you have not already)\n";
        echo "    -A or --auth-lastfm        (Re-)Authorise the application with Last.fm\n";
        echo "\n";
        echo "Debugging options:\n";
        echo "    -d or --dump:              Dump the file's complete structure and exit\n";
        echo "    -v or --verbosity <0-9>:   How much logging to output. (default: 0 (none))\n";
        echo "    -l or --log-file <file>:   Where to send logging output. (If this option is omitted, output goes to stdout)\n";
        echo "    -r or --replay:            Replay the session file, one batch per tick. (Tick by pressing enter at the console)\n"; 
        echo "    -c or --csv:               Parse the session file as a CSV, not a binary file, for testing purposes. Best used with --replay\n"; 
    }
    
    protected function getDefaultHistoryDir()
    {
        // OSX
        $dir = getenv('HOME') . '/Music/ScratchLIVE/History/Sessions';
        if(is_dir($dir)) return $dir;
        
        // Windows Vista / Windows 7 ?
        $dir = getenv('USERPROFILE') . '\Music\ScratchLIVE\History\Sessions';
        if(is_dir($dir)) return $dir;
        
        // Windows XP
        $dir = getenv('USERPROFILE') . '\My Documents\My Music\ScratchLIVE\History\Sessions';
        if(is_dir($dir)) return $dir;
        
        throw new RuntimeException("Could not find your ScratchLive History folder; it wasn't where I was expecting.");
    }
    
    protected function parseOptions(array $argv)
    {
        $this->appname = array_shift($argv);
        
        while($arg = array_shift($argv))
        {
            if($arg == '--help' || $arg == '-h')
            {
                $this->help = true;
                continue;
            }
            
            if($arg == '--dump' || $arg == '-d')
            {
                $this->dump_and_exit = true;
                continue;
            }
            
            if($arg == '--immediate' || $arg == '-i')
            {
                $this->wait_for_file = false;
                continue;
            }

            if($arg == '--log-file' || $arg == '-l')
            {
                $this->log_file = array_shift($argv);
                continue;
            }
            
            if($arg == '--verbosity' || $arg == '-v')
            {
                $this->verbosity = (int) array_shift($argv);
                continue;
            }
            
            if($arg == '--replay' || $arg == '-r')
            {
                $this->replay = true;
                continue;
            }
            
            if($arg == '--csv' || $arg == '-c')
            {
                $this->csv = true;
                continue;
            }
            
            if($arg == '--auth-lastfm' || $arg == '-A')
            {
                $this->auth_lastfm = true;
                continue;
            }
            
            if($arg == '--lastfm' || $arg == '-L')
            {
                $this->lastfm_username = array_shift($argv);
                continue;
            }
            
            $this->filename = $arg;
        }        
    }
    
    protected function setupLogging()
    {
        if($this->verbosity == 0)
        {
            L::setLogger(new NullLogger());
            return;
        }
        
        if($this->log_file)
        {
            $logger = new FileLogger();
            $logger->setLogFile($this->log_file);
        }
        else
        {
            $logger = new ConsoleLogger();
        }
        
        L::setLogger($logger);
        L::setLevel($this->verbosity);
        L::setOverrides($this->override_verbosity);
    }
    
    protected function monitor($filename)
    {
        // set up and couple the various parts of the system
        
        if($this->replay) 
        {
            // tick when the user presses enter
            $ts = new CrankHandle();
            $hfm = new SSLHistoryFileReplayer($filename);
        }
        else
        {
            // tick based on the clock
            $ts  = new TickSource();
            $hfm = new SSLHistoryFileTailMonitor($filename);
            //$hfm = new SSLHistoryFileDiffMonitor($filename);
        }
        
        if($this->csv)
        {
            $hfm = new SSLHistoryFileCSVInjector($filename);
        }
        
        $sh = new SignalHandler();
        
        $rtm = new SSLRealtimeModel();
        $rtm_printer = new SSLRealtimeModelPrinter($rtm);
        $growl_event_renderer = new SSLEventGrowlRenderer( $this->getGrowler() );
        $npm = new NowPlayingModel();
        $sm = new ScrobbleModel();
        $scrobbler = new SSLScrobblerAdaptor( $this->getScrobbler() );

        $ts->addTickObserver($hfm);
        $ts->addTickObserver($npm);
        $hfm->addDiffObserver($rtm);
        $rtm->addTrackChangeObserver($rtm_printer);
        //$rtm->addTrackChangeObserver($growl_event_renderer);
        $rtm->addTrackChangeObserver($npm);
        $rtm->addTrackChangeObserver($sm);
        $npm->addNowPlayingObserver($growl_event_renderer);
        $npm->addNowPlayingObserver($scrobbler);
        $sm->addScrobbleObserver($growl_event_renderer);
        $sm->addScrobbleObserver($scrobbler);
        
        $sh->install();
        
        // Tick tick tick. This only returns if a signal is caught
        $ts->startClock($this->sleep, $sh);
    }
    
    protected function getMostRecentFile($from_dir, $type)
    {
        $newest_mtime = 0;
        $fp = '';
        
        $di = new DirectoryIterator($from_dir);
        foreach($di as $f)
        {
            if(!$f->isFile() || !substr($f->getFilename(), -4) == '.' . $type)
                continue;
    
            $mtime = $f->getMTime();
            if($mtime > $newest_mtime)
            {
                $newest_mtime = $mtime;
                $fp = $f->getPathname();
            }
        }
        if($fp) return $fp;
        throw new RuntimeException("No $type file found in $from_dir");
    }

    protected function authLastfm()
    {        
        $vars = array();
        $vars['apiKey'] = $this->lastfm_config['api_key'];
        $vars['secret'] = $this->lastfm_config['api_secret'];
        
        $token = new lastfmApiAuth('gettoken', $vars);
        if(!empty($token->error))
        {
            throw new RuntimeException("Error fetching Last.fm auth token: " . $token->error['desc']);
        }
        
        $vars['token'] = $token->token;

        $url = 'http://www.last.fm/api/auth?api_key=' . $vars['apiKey'] . '&token=' . $vars['token'];
        
        // Automatically send the user to the auth page.
        
        // Win
        if(preg_match("/^win/i", PHP_OS))
        {
            exec('start ' . str_replace('&', '^&', $url), $output, $retval);
        }
        
        // Mac
        elseif(preg_match("/^darwin/i", PHP_OS))
        {
            exec('open "' . $url . '"', $output, $retval);            
        }
        
        echo "Please visit {$url} then press Enter...";
        
        // would be easier to do this with readline(), but some people don't have extension installed.
        if(($fp = fopen("php://stdin", 'r')) !== false) 
        {
            // read and swallow a line
            fgets($fp);
            fclose($fp);
        }
        else
        {
            throw new RuntimeException('Failed to open stdin');
        }
        
        $auth = new lastfmApiAuth('getsession', $vars);
        if(!empty($auth->error))
        {
            throw new RuntimeException("Error fetching Last.fm session key: " . $auth->error['desc'] . ". (Did you authorize the app?)");            
        }
        
        echo "Your session key is {$auth->sessionKey} for user {$auth->username} (written to lastfm-{$auth->username}.txt)\n";
        
        if(file_put_contents("lastfm-{$auth->username}.txt", $auth->sessionKey))
        {
            return;
        }
        
        throw new RuntimeException("Failed to save session key to lastfm-key.txt");
    }

    /**
     * @return Growl
     */
    protected function getGrowler()
    {
        $growler = new Growl(
            $this->growl_config['address'],
            $this->growl_config['password'],
            $this->growl_config['app_name']
        );
        
        $growler->addNotification('alert');
        $growler->register();
        return $growler;
    }
    
    /**
     * @return md_Scrobbler
     */
    protected function getScrobbler()
    {
        return new md_Scrobbler(
            $this->lastfm_username, null, 
            $this->lastfm_config['api_key'], 
            $this->lastfm_config['api_secret'], 
            $this->lastfm_config['api_sk'], 
            'xsl', '0.1'
        );
    }
}
