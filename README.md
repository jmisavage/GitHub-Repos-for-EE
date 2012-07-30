# GitHub for ExpressionEngine

Version 0.9

This is a no frills plugin for ExpessionEngine that allows you to display public repos from any GitHub user.  Its dervied from EllisLab's Twitter Timeline plugin.

# Example Usage

	{exp:github user="github_username"}
	<div class="github_repo">
		<h1>{name}</h1>
		<h2>{created_at format="%m-%d %g:%i"}</h2>
		<p>{description}</p>
	</div>
	{/exp:github}