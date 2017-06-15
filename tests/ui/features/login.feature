Feature: login

	Scenario: simple user login
		Given a regular user exists
		And I am on the login page
		When I login as a regular user with a correct password
		Then I should be redirected to a page with the title "Files - ownCloud"

	Scenario: admin login
		Given I am on the login page
		When I login with username "admin" and password "admin"
		Then I should be redirected to a page with the title "Files - ownCloud"

	Scenario: access the personal general settings page when not logged in
		Given a regular user exists
		And I go to the personal general settings page
		When I login with username "admin" and password "admin"
		Then I should be redirected to a page with the title "Settings - ownCloud"

	@skip
	Scenario: access the personal general settings page when not logged in using incorrect then correct password
		Given a regular user exists
		And I go to the personal general settings page
		When I login with username "admin" and password "invalid"
		Then I login with username "admin" and password "admin"
		Then I should be redirected to a page with the title "Settings - ownCloud"
