#!/usr/bin/env python3
"""Reproduce restricted main-only fetch refspec and interrupted test deployment."""
import pathlib, subprocess, tempfile, unittest
BRANCH='experiment/independent-wireguard-v1'
def git(cwd,*args,check=True):
    return subprocess.run(['git','-C',str(cwd),*args],check=check,text=True,capture_output=True).stdout.strip()
class UpdateRef(unittest.TestCase):
    def test_main_only_clone_and_retry_after_local_branch_creation(self):
        script=(pathlib.Path(__file__).parents[1]/'wireguard/update-test.sh').read_text()
        self.assertIn('git fetch origin "refs/heads/$branch:refs/remotes/origin/$branch"',script)
        with tempfile.TemporaryDirectory() as folder:
            root=pathlib.Path(folder); remote=root/'remote.git'; seed=root/'seed'; clone=root/'test'
            subprocess.run(['git','init','--bare',str(remote)],check=True,capture_output=True)
            subprocess.run(['git','init','-b','main',str(seed)],check=True,capture_output=True)
            git(seed,'config','user.email','test@example.invalid');git(seed,'config','user.name','Test')
            (seed/'file').write_text('base');git(seed,'add','.');git(seed,'commit','-m','base');base=git(seed,'rev-parse','HEAD')
            git(seed,'remote','add','origin',str(remote));git(seed,'push','origin','main')
            git(seed,'switch','-c',BRANCH);(seed/'file').write_text('feature');git(seed,'commit','-am','feature');feature=git(seed,'rev-parse','HEAD');git(seed,'push','origin',BRANCH)
            subprocess.run(['git','clone','--single-branch','--branch','main',str(remote),str(clone)],check=True,capture_output=True)
            # The old fetch succeeds but cannot establish the requested upstream.
            git(clone,'fetch','origin',BRANCH)
            missing=subprocess.run(['git','-C',str(clone),'show-ref','--verify','--quiet','refs/remotes/origin/'+BRANCH],capture_output=True)
            self.assertNotEqual(missing.returncode,0)
            git(clone,'switch','-c',BRANCH)
            self.assertEqual(git(clone,'rev-parse','HEAD'),base)
            # Retry uses an explicit destination and preserves main and local files.
            (clone/'.user.ini').write_text('unchanged')
            git(clone,'config','--add','remote.origin.fetch','+refs/heads/'+BRANCH+':refs/remotes/origin/'+BRANCH)
            for _ in range(2):
                git(clone,'fetch','origin','refs/heads/'+BRANCH+':refs/remotes/origin/'+BRANCH)
                git(clone,'branch','--set-upstream-to=origin/'+BRANCH,BRANCH)
                git(clone,'merge','--ff-only','refs/remotes/origin/'+BRANCH)
                self.assertEqual(git(clone,'rev-parse','HEAD'),feature)
                self.assertEqual(git(clone,'rev-parse','main'),base)
                self.assertEqual((clone/'.user.ini').read_text(),'unchanged')
                self.assertEqual(git(clone,'rev-parse','--abbrev-ref','@{upstream}'),'origin/'+BRANCH)
if __name__=='__main__':unittest.main()
