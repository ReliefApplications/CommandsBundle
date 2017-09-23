<?php

namespace RA\CommandsBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Symfony\Component\Console\Question\ConfirmationQuestion;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

/**
*
* Here is the list of checks we are trying to install before each commit :
*
* 0. Check dangerous files
* 1. Syntax check with php lint (“php -l”): We check every committed file has a valid PHP syntax.
* 2. Sync check of composer.json and composer.lock files: We check these two files are committed
*    together in order to avoid committing the json but not the lock and generate some issue to another developers.
* 3. PHP CS Fixer check: With the –dry-run parameter it does not fix, just say what the problems are.
*    With the –fixers parameter you can control what fixers you want to execute.
* 4. PHP Code Sniffer check: Same as before, but another rule that checks another rules.
* 5. PHPMD: We have enabled the controversial rules.
* 6. Unit Testing check: We run around 3.000 tests right now.
*
* Source : Code inspired from https://carlosbuenosvinos.com/write-your-git-hooks-in-php-and-keep-them-under-git-control/
*/

class RaPrecommitCommand extends ContainerAwareCommand
{
    const PHP_FILES_IN_SRC = '/^src\/(.*)(\.php)$/';
    const PHP_FILES_IN_TESTS = '/^tests\/(.*)(\.php)$/';

    const STYLE_WARNING = "comment";
    const STYLE_ERROR = "error";
    const STYLE_INFO = "info";

    private $configuration;
    private $projectPath;
    private $jetonFile;
    private $messages;

    private $input;
    private $output;

    public function __construct(){

        parent::__construct( );

        $this->configuration = [];
        $this->path = "./";
        $this->messages = [
            'goback' => "<".self::STYLE_WARNING.">Please check your mistakes and try again !</".self::STYLE_WARNING.">",
            'test' => "<".self::STYLE_WARNING.">Please check your tests !</".self::STYLE_WARNING.">"
        ];
    }

    protected function configure(){
        $this
            ->setName('ra:precommit')
            ->setDescription('Check your code before to commit')
            ->addArgument('commitMessage', InputArgument::OPTIONAL, 'Commit Message')
        ;

    }

    protected function execute(InputInterface $input, OutputInterface $output){
        $this->input = $input;
        $this->output = $output;
        $argCommitMessage = $input->getArgument('commitMessage');

        $style = "fg=white;options=bold;bg=cyan";
        $stylChecking = "fg=cyan";
        $this->setMessage('done', "Done !", "info");

        $output->write("Mdm Code Quality Tool", $style);

        $this->loadConfiguration($this->output);

        $this->resetJeton();

        //check dangerous files
        $this->write("* Checking dangerous files ...", $stylChecking);
        $this->checkDangerousFiles( function ($status, $message = '') use ($output) {
            if( ! $status){
                $this->logger($message);
                exit(1);
            }
            $this->output->writeln($this->getMessage('done'));
        });

        exec("git add .");

        $committedFiles = $this->extractCommitedFiles();
        var_dump($committedFiles);

        exec("git reset");

        //check composer
        $this->write("* Checking composer ...", $stylChecking);
        $this->checkComposer($committedFiles, function ($status, $message = '') use ($output) {
            if( ! $status){
                $this->logger($message);
                exit(1);
            }
            $this->output->writeln($this->getMessage('done'));
        });

        //check linter
        $this->write("* Checking Linter ...", $stylChecking);
        $this->checkPhpLint($committedFiles, function ($status, $message = '') use ($output) {
            if( ! $status){
                $this->logger($message);
                exit(1);
            }
            $this->output->writeln($this->getMessage('done'));
        });

        //check phpmd
        $this->write("* Checking Syntax (PhpMd) ...", $stylChecking);
        $this->checkPhPmd($committedFiles, function ($status, $message = '') use ($output) {
            if( ! $status){
                $this->logger($message);
                exit(1);
            }
            $this->output->writeln($this->getMessage('done'));
        });

        //Php Unit
        $this->write("* Checking Tests ...", $stylChecking);
        $this->checkTests(function ($status) use ($output) {
             if( ! $status){
                 $output->writeln($this->getMessage('test'));
                 exit(1);
             }
             $this->output->writeln($this->getMessage('done'));
        });


        $this->output->writeln('<info>Hey dude, you passed the precommit !! Great Job !!</info>');

        if(! empty($argCommitMessage)){
            $arg = $input->getArgument('commitMessage');

            exec("git add .");
            exec("git commit -m \"$arg\"", $out);

            $this->output->writeln($out);
        }

        $this->setJeton();



    }

    private function loadConfiguration(OutputInterface $output){
        //configuration
        $reflectionClass = new \ReflectionClass($this);
        $className = $reflectionClass->getShortName();
        $configurationFile = dirname(__FILE__).'/'.$className.'.json';

        if(file_exists($configurationFile)){
            $content = file_get_contents($configurationFile);
            $this->configuration = json_decode($content,true);
        }else{
            $output->writeln("<comment>No configuration file found in `$configurationFile`</comment>");
        }
        //project path
        $this->projectPath = dirname($this->getContainer()->get('kernel')->getRootDir());

        //jeton
        $this->jetonFile = $this->projectPath.'/var/cache/dev/jeton';
    }

    //
    //  STYLES
    //

    private function setJeton(){

        if(file_exists($this->jetonFile)){
            unlink($this->jetonFile);
        }

        $handle = fopen($this->jetonFile, "w+");
        fclose($handle);
    }

    private function resetJeton(){

        if(file_exists($this->jetonFile)){
            unlink($this->jetonFile);
        }
    }

    private function write($content, $style='info'){
        $this->output->writeln("<$style>$content</$style>");
    }

    private function setMessage($key, $content, $style='info'){
        $this->messages[$key] = "<$style>$content</$style>";
        return $this;
    }

    private function getMessage($key){
        return $this->messages[$key];
    }

    private function logger($message){
        if( ! empty($message)){
            $this->setMessage('tmp', $message, self::STYLE_WARNING);
            $this->output->writeln($this->getMessage('tmp'));
        }
        $this->output->writeln($this->getMessage('goback'));
    }

    //
    //  GIT
    //

    private function getLastCommitId(){
       $output = array();
       $rc = 0;

       exec('git log --format="%H" -n 1 | cat', $output, $rc);
       return is_array($output) ? $output[0] : "";
    }

    private function extractCommitedFiles(){
       $output = array();
       $rc = 0;

       $lastCommitId = $this->getLastCommitId();

       //check if commits are available
       $output = array();
       exec('git rev-parse --verify HEAD 2> /dev/null', $output, $rc);
       if ($rc == 0) {
           $lastCommitId = 'HEAD';
       }

       //committed files
       $output = array();
       exec("git diff-index --cached --name-status $lastCommitId | egrep '^(A|M)' | awk '{print $2;}'", $output);

       return $output;
    }

    //
    //  TASKS
    //

    private function checkDangerousFiles(\Closure $callback){

       $noFoundMessage = "<".self::STYLE_WARNING.">No dangerous files found.</".self::STYLE_WARNING.">";

       if(empty($this->configuration['files'])){
           return $callback(true, $noFoundMessage);
       }

       if(empty($this->configuration['files']['dangerous'])){
           return $callback(true, $noFoundMessage);
       }

       //HELPER
       $helper = $this->getHelper('question');
       $confirmQuestion = new ConfirmationQuestion('Can you confirm ? (y/n) <comment>[y]</comment>', true, '/^(y|j)/i');

       $files = $this->configuration['files']['dangerous'];

       foreach ($files as $key => $value) {
           $filename = $this->projectPath.'/'.$value;

           //Existence
           if(! file_exists($filename)){

               $this->output->writeln("<".self::STYLE_ERROR.">A dangerous file is missing : `'.$filename.'`</".self::STYLE_ERROR.">");
               $this->output->writeln("<".self::STYLE_ERROR.">You might delete it.</".self::STYLE_ERROR.">");

               if (!$helper->ask($this->input, $this->output, $confirmQuestion)) {
                   return $callback(false, "Be careful when modifying dangerous files.");
               }
           }

           //Modification
           else{
               $process = new Process('git diff '.$filename);
               $process->start();

               foreach ($process as $type => $data) {
                   if ($process::OUT === $type) {
                       if(! empty($data)){

                           $this->output->writeln("<".self::STYLE_WARNING.">A dangerous file has changed : `'.$filename.'`</".self::STYLE_WARNING.">");
                           $this->output->writeln("<".self::STYLE_WARNING.">You might change it.</".self::STYLE_WARNING.">");
                           echo $data."\n";

                           if (!$helper->ask($this->input, $this->output, $confirmQuestion)) {
                               return $callback(false, "Be careful when modifying dangerous files.");
                           }
                       }

                       return $callback(true);

                   } else {
                       return $callback(false, $data);
                   }
               }
           }
       }

       return $callback(true);
    }

    private function checkComposer($files, \Closure $callback){
       $composerJsonDetected = false;
       $composerLockDetected = false;

       foreach ($files as $file) {
           if ($file === 'composer.json') {
               $composerJsonDetected = true;
           }

           if ($file === 'composer.lock') {
               $composerLockDetected = true;
           }
       }

       return ($composerJsonDetected && !$composerLockDetected)
            ? $callback(false, 'composer.lock must be commited if composer.json is modified!')
            : $callback(true);
    }

    // SYNTAX ERRORS CHECKER
    private function checkPhpLint($files, \Closure $callback){

       $needle = '/(\.php)|(\.inc)$/';

       foreach ($files as $file) {
           if (!preg_match($needle, $file)) {
               continue;
           }

           $processBuilder = new ProcessBuilder(array('php', '-l', $file));
           $process = $processBuilder->getProcess();
           $process->run();

           if (! $process->isSuccessful()) {

               $message = "`$file` failed the PHPLint test.\n";
               $message .= sprintf('<error>%s</error>', trim($process->getErrorOutput()));

               return $callback(false, $message);
           }
       }

       return $callback(true);
    }

    private function checkPhPmd($files, \Closure $callback){
       $needle = self::PHP_FILES_IN_SRC;

       foreach ($files as $file) {
           if (!preg_match($needle, $file)) {
               continue;
           }

           echo "$file analysis in progress ... \n";

           $processBuilder = new ProcessBuilder(['php', 'vendor/bin/phpmd', $file, 'text', 'controversial']);
           $processBuilder->setWorkingDirectory($this->projectPath);
           $process = $processBuilder->getProcess();
           $process->run();

           if (! $process->isSuccessful()) {
               $message = "`$file` failed the PHPMd test.\n";
               $message .= sprintf('<error>%s</error>', trim($process->getErrorOutput()));
               $message .= sprintf('<info>%s</info>', trim($process->getOutput()));

               return $callback(false, $message);
           }
       }

       return $callback(true);
    }

    private function checkTests(\Closure $callback){

       $processBuilder = new ProcessBuilder(array('phpunit'));
       $processBuilder->setWorkingDirectory($this->projectPath);
       $processBuilder->setTimeout(3600);
       $phpunit = $processBuilder->getProcess();

       $phpunit->run(function ($type, $buffer) {
           $this->output->write($buffer);
       });

       return $callback($phpunit->isSuccessful());
    }

}
