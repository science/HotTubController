#!/usr/bin/env python3
"""
Claude Code Subagent: Backend Test Runner

This subagent runs the entire backend test suite, processes results, and returns
only the failures and errors with detailed information including filename,
line number, error message, and other available details.

After processing, it clears the Claude Code context and injects just the
test errors as a clean message.
"""

import subprocess
import json
import re
import sys
import os
from pathlib import Path
from typing import Dict, List, Any
import xml.etree.ElementTree as ET

class BackendTestRunner:
    def __init__(self, backend_dir: str = "backend"):
        self.backend_dir = Path(backend_dir)
        self.junit_xml_path = self.backend_dir / "tests" / "results" / "junit.xml"
        
    def run_tests(self) -> Dict[str, Any]:
        """Run the full backend test suite and capture output"""
        print("🧪 Running backend test suite...")
        
        # Ensure results directory exists
        os.makedirs(self.junit_xml_path.parent, exist_ok=True)
        
        try:
            # Change to backend directory and run tests
            cmd = ["make", "test"]
            result = subprocess.run(
                cmd,
                cwd=self.backend_dir,
                capture_output=True,
                text=True,
                timeout=300  # 5 minute timeout
            )
            
            return {
                "return_code": result.returncode,
                "stdout": result.stdout,
                "stderr": result.stderr,
                "success": result.returncode == 0
            }
            
        except subprocess.TimeoutExpired:
            return {
                "return_code": -1,
                "stdout": "",
                "stderr": "Test suite timed out after 5 minutes",
                "success": False
            }
        except Exception as e:
            return {
                "return_code": -1,
                "stdout": "",
                "stderr": f"Failed to run tests: {str(e)}",
                "success": False
            }
    
    def parse_phpunit_output(self, stdout: str, stderr: str) -> List[Dict[str, Any]]:
        """Parse PHPUnit text output for failures and errors"""
        failures = []
        
        # Combine stdout and stderr for parsing
        full_output = stdout + "\n" + stderr
        
        # Pattern to match PHPUnit failure/error blocks
        # Look for patterns like:
        # 1) Tests\Unit\SomeTest::testMethod
        # Failed asserting that false is true.
        # /path/to/file.php:123
        
        lines = full_output.split('\n')
        i = 0
        
        while i < len(lines):
            line = lines[i].strip()
            
            # Look for numbered test failures/errors
            if re.match(r'^\d+\)\s+', line):
                failure = self._parse_failure_block(lines, i)
                if failure:
                    failures.append(failure)
            
            i += 1
        
        return failures
    
    def _parse_failure_block(self, lines: List[str], start_idx: int) -> Dict[str, Any]:
        """Parse a single failure block from PHPUnit output"""
        try:
            # Extract test name from first line
            header_line = lines[start_idx].strip()
            match = re.match(r'^\d+\)\s+(.+)$', header_line)
            if not match:
                return None
                
            test_name = match.group(1)
            
            # Look for the error message and stack trace
            error_lines = []
            file_info = None
            
            i = start_idx + 1
            while i < len(lines) and not re.match(r'^\d+\)\s+', lines[i]):
                line = lines[i].strip()
                
                # Check if this line contains file path and line number
                if re.match(r'^/.+\.php:\d+', line):
                    if not file_info:  # Take the first file reference (usually the test file)
                        parts = line.split(':')
                        file_info = {
                            'file': parts[0],
                            'line': int(parts[1]) if len(parts) > 1 and parts[1].isdigit() else None
                        }
                else:
                    # Collect error message lines
                    if line and not line.startswith('---') and not line.startswith('+++'):
                        error_lines.append(line)
                
                i += 1
            
            return {
                'test_name': test_name,
                'error_message': '\n'.join(error_lines).strip(),
                'file': file_info['file'] if file_info else 'Unknown',
                'line': file_info['line'] if file_info else None,
                'type': 'failure'
            }
            
        except Exception:
            return None
    
    def parse_junit_xml(self) -> List[Dict[str, Any]]:
        """Parse JUnit XML file for structured test results"""
        failures = []
        
        if not self.junit_xml_path.exists():
            return failures
            
        try:
            tree = ET.parse(self.junit_xml_path)
            root = tree.getroot()
            
            # Find all testcase elements with failures or errors
            for testcase in root.findall('.//testcase'):
                test_name = testcase.get('name', 'Unknown')
                class_name = testcase.get('class', 'Unknown')
                file_name = testcase.get('file', 'Unknown')
                line_num = testcase.get('line')
                
                full_test_name = f"{class_name}::{test_name}"
                
                # Check for failures
                for failure in testcase.findall('failure'):
                    failures.append({
                        'test_name': full_test_name,
                        'error_message': failure.text or failure.get('message', ''),
                        'file': file_name,
                        'line': int(line_num) if line_num and line_num.isdigit() else None,
                        'type': 'failure',
                        'failure_type': failure.get('type', 'Unknown')
                    })
                
                # Check for errors  
                for error in testcase.findall('error'):
                    failures.append({
                        'test_name': full_test_name,
                        'error_message': error.text or error.get('message', ''),
                        'file': file_name,
                        'line': int(line_num) if line_num and line_num.isdigit() else None,
                        'type': 'error',
                        'error_type': error.get('type', 'Unknown')
                    })
                    
        except Exception as e:
            print(f"Warning: Could not parse JUnit XML: {e}")
            
        return failures
    
    def format_results(self, failures: List[Dict[str, Any]], test_result: Dict[str, Any]) -> str:
        """Format test failures into a clean message for Claude Code"""
        
        if not failures:
            return "✅ All backend tests passed successfully! No failures or errors to report."
        
        # Group failures by file for better organization
        failures_by_file = {}
        for failure in failures:
            file_path = failure.get('file', 'Unknown')
            if file_path not in failures_by_file:
                failures_by_file[file_path] = []
            failures_by_file[file_path].append(failure)
        
        output = [
            f"❌ Backend Test Suite Results: {len(failures)} failure(s)/error(s) found",
            "",
            "## Test Failures and Errors",
            ""
        ]
        
        for file_path, file_failures in failures_by_file.items():
            output.append(f"### {file_path}")
            output.append("")
            
            for failure in file_failures:
                test_name = failure.get('test_name', 'Unknown Test')
                line = failure.get('line')
                error_msg = failure.get('error_message', 'No error message available')
                failure_type = failure.get('failure_type', failure.get('error_type', ''))
                
                # Format the failure entry
                line_info = f":{line}" if line else ""
                type_info = f" ({failure_type})" if failure_type else ""
                
                output.extend([
                    f"**{test_name}**{type_info}",
                    f"Location: `{file_path}{line_info}`",
                    "",
                    "```",
                    error_msg,
                    "```",
                    ""
                ])
        
        # Add summary information
        output.extend([
            "---",
            "",
            f"**Test Run Summary:**",
            f"- Return Code: {test_result.get('return_code', 'Unknown')}",
            f"- Total Failures/Errors: {len(failures)}",
            f"- Test Suite: Backend (PHPUnit)"
        ])
        
        return "\n".join(output)
    
    def run(self) -> str:
        """Main execution method"""
        # Run the test suite
        test_result = self.run_tests()
        
        # Parse failures from both output and XML
        text_failures = self.parse_phpunit_output(test_result["stdout"], test_result["stderr"])
        xml_failures = self.parse_junit_xml()
        
        # Combine and deduplicate failures
        all_failures = text_failures + xml_failures
        
        # Remove duplicates based on test name and file
        seen = set()
        unique_failures = []
        for failure in all_failures:
            key = (failure.get('test_name'), failure.get('file'), failure.get('line'))
            if key not in seen:
                seen.add(key)
                unique_failures.append(failure)
        
        # Format results
        return self.format_results(unique_failures, test_result)

def main():
    """Main entry point for the subagent"""
    runner = BackendTestRunner()
    result = runner.run()
    
    # Output the result for Claude Code to process
    print(result)
    
    # Also save to a temp file that Claude Code can read
    temp_file = Path("/tmp/backend_test_results.md")
    temp_file.write_text(result)
    print(f"\nResults also saved to: {temp_file}")

if __name__ == "__main__":
    main()